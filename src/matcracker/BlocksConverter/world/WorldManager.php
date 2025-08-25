<?php

declare(strict_types=1);

namespace matcracker\BlocksConverter\world;

use Exception;
use FilesystemIterator;
use RuntimeException;
use matcracker\BlocksConverter\BlocksMap;
use matcracker\BlocksConverter\Loader;
use matcracker\BlocksConverter\utils\Utils;
use pocketmine\block\Block;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\SubChunk;
use pocketmine\world\format\io\exception\CorruptedChunkException;
use pocketmine\world\format\io\leveldb\LevelDB;
use pocketmine\world\format\io\region\Anvil;
use pocketmine\world\format\io\region\McRegion;
use pocketmine\world\format\io\region\PMAnvil;
use pocketmine\world\World;
use pocketmine\block\tile\Sign;
use pocketmine\utils\Binary;
use pocketmine\utils\TextFormat;
use RegexIterator;
use function is_array;
use function json_decode;
use function microtime;
use function number_format;
use function strlen;
use function substr;
use const PHP_EOL;

class WorldManager{
	/**@var Loader */
	private $loader;
	/**@var World */
	private $world;
	/**@var bool */
	private $isConverting = false;
	/** @var string */
	private $worldName;

	private $convertedBlocks = 0;
	private $convertedSigns = 0;

	public function __construct(Loader $loader, World $world){
		$this->loader = $loader;
		$this->world = $world;
		$this->worldName = $world->getFolderName();
	}

	public function getWorld() : World{
		return $this->world;
	}

	public function backup() : void{
		$this->loader->getLogger()->debug("Creating a backup of $this->worldName");
		$srcPath = "{$this->loader->getServer()->getDataPath()}/worlds/$this->worldName";
		$destPath = "{$this->loader->getDataFolder()}/backups/$this->worldName";
		Utils::recursiveCopyDirectory($srcPath, $destPath);
		$this->loader->getLogger()->debug("Backup successfully created");
	}

	public function restore() : void{
		$this->loader->getLogger()->debug("Restoring a backup of $this->worldName");
		$srcPath = "{$this->loader->getDataFolder()}/backups/$this->worldName";
		if(!$this->hasBackup()){
			throw new RuntimeException("This world never gets a backup.");
		}

		$destPath = "{$this->loader->getServer()->getDataPath()}/worlds/$this->worldName";

		Utils::recursiveCopyDirectory($srcPath, $destPath);
		$this->loader->getLogger()->debug("Successfully restored");
	}

	public function hasBackup() : bool{
		return file_exists("{$this->loader->getDataFolder()}/backups/$this->worldName");
	}

	public function unloadWorld() : bool{
		return $this->loader->getServer()->getWorldManager()->unloadWorld($this->world);
	}

	public function isConverting() : bool{
		return $this->isConverting;
	}

	public function startConversion(bool $toBedrock = true) : void{
		//Conversion report variables
		$status = true;
		$totalChunks = $convertedChunks = $corruptedChunks = 0;
		$this->convertedBlocks = $this->convertedSigns = 0;

		if(!$this->hasBackup()){
			$this->loader->getLogger()->warning("The world \"$this->worldName\" will be converted without a backup.");
		}

		foreach($this->loader->getServer()->getOnlinePlayers() as $player){
			$player->kick("The server is running a world conversion, try to join later.");
		}

		$this->loader->getLogger()->debug("Starting world \"$this->worldName\" conversion...");
		$this->isConverting = true;
		$provider = $this->world->getProvider();
		$blockMap = $toBedrock ? BlocksMap::get() : BlocksMap::reverse();

		$conversionStart = microtime(true);
		try{
			if($provider instanceof LevelDB){
				foreach($provider->getDatabase()->getIterator() as $key => $_){
					// In PMMP5, the TAG_VERSION constant has been removed
					// We'll use the raw value (v) instead
					if(strlen($key) === 9 and substr($key, -1) === "v"){
						$chunkX = Binary::readLInt(substr($key, 0, 4));
						$chunkZ = Binary::readLInt(substr($key, 4, 4));
						try{
							//Try to load the chunk. If success returns it.
							if(($chunk = $this->world->getChunk($chunkX, $chunkZ, false)) !== null){
								if($hasChanged = $this->convertChunk($chunk, $blockMap, $toBedrock, $chunkX, $chunkZ)){
									$convertedChunks++;
								}

								//Unload the chunk to free the memory.
								if(!$this->world->unloadChunk($chunkX, $chunkZ, true, $hasChanged)){
									$this->loader->getLogger()->debug("Could not unload the chunk[$chunkX;$chunkZ]");
								}
							}else{
								$this->loader->getLogger()->debug("Could not load chunk[$chunkX;$chunkZ]");
							}
							$totalChunks++;
						}catch(CorruptedChunkException $e){
							$corruptedChunks++;
						}
					}
				}
			}else{
				foreach($this->createRegionIterator() as $region){
					$regionX = (int) $region[1];
					$regionZ = (int) $region[2];
					$rX = $regionX << 5;
					$rZ = $regionZ << 5;
					for($chunkX = $rX; $chunkX < $rX + 32; ++$chunkX){
						for($chunkZ = $rZ; $chunkZ < $rZ + 32; ++$chunkZ){
							try{
								//Try to load the chunk. If success returns it.
								if(($chunk = $this->world->getChunk($chunkX, $chunkZ, false)) !== null){
									if($hasChanged = $this->convertChunk($chunk, $blockMap, $toBedrock, $chunkX, $chunkZ)){
										$convertedChunks++;
									}

									//Unload the chunk to free the memory.
									if(!$this->world->unloadChunk($chunkX, $chunkZ, true, $hasChanged)){
										$this->loader->getLogger()->debug("Could not unload the chunk[$chunkX;$chunkZ]");
									}
								}else{
									$this->loader->getLogger()->debug("Could not load chunk[$chunkX;$chunkZ]");
								}
							}catch(CorruptedChunkException $e){
								$corruptedChunks++;
							}
							$totalChunks++;
						}
					}
				}
			}
		}catch(Exception $e){
			$this->loader->getLogger()->critical($e);
			$status = false;
		}

		$this->isConverting = false;
		$this->loader->getLogger()->debug("Conversion finished! Printing full report...");

		$report = PHP_EOL . TextFormat::LIGHT_PURPLE . "--- Conversion Report ---" . TextFormat::EOL;
		$report .= TextFormat::AQUA . "Status: " . ($status ? (TextFormat::DARK_GREEN . "Completed") : (TextFormat::RED . "Aborted")) . TextFormat::EOL;
		$report .= TextFormat::AQUA . "World name: " . TextFormat::GREEN . $this->worldName . TextFormat::EOL;
		$report .= TextFormat::AQUA . "Execution time: " . TextFormat::GREEN . number_format((microtime(true) - $conversionStart), 1) . " second(s)" . TextFormat::EOL;
		$report .= TextFormat::AQUA . "Total chunks: " . TextFormat::GREEN . $totalChunks . TextFormat::EOL;
		$report .= TextFormat::AQUA . "Corrupted chunks: " . TextFormat::GREEN . $corruptedChunks . TextFormat::EOL;
		$report .= TextFormat::AQUA . "Chunks converted: " . TextFormat::GREEN . $convertedChunks . TextFormat::EOL;
		$report .= TextFormat::AQUA . "Blocks converted: " . TextFormat::GREEN . $this->convertedBlocks . TextFormat::EOL;
		$report .= TextFormat::AQUA . "Signs converted: " . TextFormat::GREEN . $this->convertedSigns . TextFormat::EOL;
		$report .= TextFormat::LIGHT_PURPLE . "----------";

		$this->loader->getLogger()->info($report);
	}

	/**
	 * @param Chunk $chunk
	 * @param array<string, array<int, int>> $blockMap Map of source state IDs to target state IDs
	 * @param bool $toBedrock
	 *
	 * @return bool true if the chunk has been converted otherwise false.
	 */
	private function convertChunk(Chunk $chunk, array $blockMap, bool $toBedrock, int $cx, int $cz) : bool{
		$hasChanged = false;
		$signChunkConverted = false;
		$registry = RuntimeBlockStateRegistry::getInstance();

		for($y = 0; $y < 256; $y++){
			$subChunk = $chunk->getSubChunk($y >> 4);
			if($subChunk->isEmptyFast()){
				continue;
			}

			for($x = 0; $x < 16; $x++){
				for($z = 0; $z < 16; $z++){
					// Get the block at this position
					$blockStateId = $subChunk->getBlockStateId($x, $y & 0x0f, $z);

					// Skip air blocks
					$block = $registry->fromStateId($blockStateId);
					if($block->getTypeId() === "minecraft:air"){
						continue;
					}

					// At the moment support sign conversion only from java to bedrock
					if(($block->getTypeId() === "minecraft:standing_sign" || $block->getTypeId() === "minecraft:wall_sign") && $toBedrock){
						if($signChunkConverted){
							continue;
						}

						$this->loader->getLogger()->debug("Found a chunk[$cx;$cz] containing signs...");
						$tiles = $chunk->getTiles();
						foreach($tiles as $tile){
							if(!$tile instanceof Sign){
								continue;
							}

							$text = $tile->getText();
							for($i = 0; $i < 4; $i++){
								$line = "";
								$data = json_decode($text[$i] ?? "", true);
								if(is_array($data)){
									if(isset($data["extra"])){
										foreach($data["extra"] as $extraData){
											$line .= Utils::getTextFormatColors()[($extraData["color"] ?? "black")] . ($extraData["text"] ?? "");
										}
									}
									$line .= $data["text"] ?? "";
								}else{
									$line = (string) $data;
								}
								$text[$i] = $line;
							}
							$tile->setText($text[0] ?? "", $text[1] ?? "", $text[2] ?? "", $text[3] ?? "");

							$hasChanged = true;
							$this->convertedSigns++;
						}
						$signChunkConverted = true;
					}else{
						// Convert the block state ID to a string for map lookup
						$sourceStateIdStr = (string)$blockStateId;

						// Check if we have a mapping for this block state
						if(!isset($blockMap[$sourceStateIdStr])){
							continue;
						}

						// Get the target block state ID
						$targetStateId = $blockMap[$sourceStateIdStr][0] ?? null;
						if($targetStateId === null){
							continue;
						}

						// Get the source and target blocks for logging
						$sourceBlock = $registry->fromStateId($blockStateId);
						$targetBlock = $registry->fromStateId($targetStateId);

						$this->loader->getLogger()->debug("Replaced block \"{$sourceBlock->getTypeId()}\" with \"{$targetBlock->getTypeId()}\"");

						// Set the new block
						$subChunk->setBlockStateId($x, $y & 0x0f, $z, $targetStateId);
						$hasChanged = true;
						$this->convertedBlocks++;
					}
				}
			}
		}

		return $hasChanged;
	}

	private function createRegionIterator() : RegexIterator{
		return new RegexIterator(
			new FilesystemIterator(
				$this->world->getProvider()->getPath() . 'region/',
				FilesystemIterator::CURRENT_AS_PATHNAME | FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS
			),
			'/\/r\.(-?\d+)\.(-?\d+)\.' . $this->getWorldExtension() . '$/',
			RegexIterator::GET_MATCH
		);
	}

	private function getWorldExtension() : ?string{
		$provider = $this->world->getProvider();
		if($provider instanceof Anvil){
			return "mca"; // Hardcoded value for Anvil format
		}else if($provider instanceof McRegion){
			return "mcr"; // Hardcoded value for McRegion format
		}else if($provider instanceof PMAnvil){
			return "mcapm"; // Hardcoded value for PMAnvil format
		}

		return null;
	}
}
