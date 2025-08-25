<?php

declare(strict_types=1);

namespace matcracker\BlocksConverter;

use pocketmine\block\Block;
use pocketmine\block\RuntimeBlockStateRegistry;

final class BlocksMap{

	/** @var array<string, array<int, int>> Map of source block state IDs to target block state IDs */
	private static array $MAP = [];

	/**
	 * Initialize the block mapping
	 */
	public static function load() : void{
		// In PMMP5, we use RuntimeBlockStateRegistry to get block state IDs
		$registry = RuntimeBlockStateRegistry::getInstance();

		// Define common block mappings
		// This is a simplified mapping that focuses on the most commonly used blocks
		// The full mapping would be much more extensive

		// Dirt to Podzol
		self::addMapping("minecraft:dirt", 0, "minecraft:podzol", 0);

		// Stone Button mappings
		for($i = 1, $j = 5; $i <= 5; $i++, $j--){
			self::addMapping("minecraft:stone_button", $i, "minecraft:stone_button", $j);
		}

		// Invisible Bedrock to Stained Glass
		for($i = 0; $i <= 15; $i++){
			self::addMapping("minecraft:invisible_bedrock", 0, "minecraft:stained_glass", $i);
		}

		// Trapdoor mappings
		for($i = 0; $i <= 15; $i++){
			$targetMeta = 15 - $i;
			self::addMapping("minecraft:trapdoor", $i, "minecraft:trapdoor", $targetMeta);
		}

		// Iron Trapdoor mappings
		for($i = 0; $i <= 15; $i++){
			$targetMeta = 15 - $i;
			self::addMapping("minecraft:iron_trapdoor", $i, "minecraft:iron_trapdoor", $targetMeta);
		}

		// End Rod mappings
		for($i = 0; $i <= 15; $i++){
			self::addMapping("minecraft:grass_path", 0, "minecraft:end_rod", $i);
		}

		// Override specific End Rod directions
		self::addMapping("minecraft:grass_path", 0, "minecraft:end_rod", 3, 2);
		self::addMapping("minecraft:grass_path", 0, "minecraft:end_rod", 2, 3);
		self::addMapping("minecraft:grass_path", 0, "minecraft:end_rod", 5, 4);
		self::addMapping("minecraft:grass_path", 0, "minecraft:end_rod", 4, 5);

		// Glazed Terracotta mappings
		$colors = ["white", "orange", "magenta", "light_blue", "yellow", "lime", "pink", "gray", 
				  "silver", "cyan", "purple", "blue", "brown", "green", "red", "black"];

		foreach($colors as $index => $color){
			// Map each color to different facing directions
			self::addMapping("minecraft:{$color}_glazed_terracotta", 0, "minecraft:{$color}_glazed_terracotta", 3, 0);
			self::addMapping("minecraft:{$color}_glazed_terracotta", 0, "minecraft:{$color}_glazed_terracotta", 4, 1);
			self::addMapping("minecraft:{$color}_glazed_terracotta", 0, "minecraft:{$color}_glazed_terracotta", 2, 2);
			self::addMapping("minecraft:{$color}_glazed_terracotta", 0, "minecraft:{$color}_glazed_terracotta", 5, 3);
		}
	}

	/**
	 * Add a mapping between two blocks
	 * 
	 * @param string $sourceBlockId Source block identifier
	 * @param int $sourceMeta Source block metadata
	 * @param string $targetBlockId Target block identifier
	 * @param int $targetMeta Target block metadata
	 * @param int|null $sourceMetaOverride Optional override for source metadata
	 */
	private static function addMapping(string $sourceBlockId, int $sourceMeta, string $targetBlockId, int $targetMeta, ?int $sourceMetaOverride = null) : void{
		$registry = RuntimeBlockStateRegistry::getInstance();

		// Get the source block
		$sourceBlock = $registry->fromStateId(BlockStringParser::parse($sourceBlockId));
		if($sourceBlock === null){
			return; // Skip if block doesn't exist
		}

		// Set the metadata/damage value
		if($sourceMeta > 0){
			// In PMMP5, we need to get the correct block state with the given metadata
			// This is a simplified approach - in a real implementation, you'd need to handle
			// block states properly based on the block typ
		}

		// Get the source state ID
		$sourceStateId = (string)$sourceBlock->getStateId();

		// Get the target block
		$targetBlock = $registry->fromStateId(BlockStringParser::parse($targetBlockId));
		if($targetBlock === null){
			return; // Skip if block doesn't exist
		}

		// Set the metadata/damage value
		if($targetMeta > 0){
            //TODO: Find out how to replace that
		}

		// Get the target state ID
		$targetStateId = $targetBlock->getStateId();

		// Store the mapping
		$actualSourceMeta = $sourceMetaOverride ?? $sourceMeta;
		if(!isset(self::$MAP[$sourceStateId])){
			self::$MAP[$sourceStateId] = [];
		}
		self::$MAP[$sourceStateId][$actualSourceMeta] = $targetStateId;
	}

	/**
	 * Get the reversed mapping (target to source)
	 * 
	 * @return array<string, array<int, string>> Map of target state IDs to source state IDs
	 */
	public static function reverse() : array{
		$newMap = [];
		foreach(self::$MAP as $sourceStateId => $metaMap){
			foreach($metaMap as $sourceMeta => $targetStateId){
				$targetStateIdStr = (string)$targetStateId;
				if(!isset($newMap[$targetStateIdStr])){
					$newMap[$targetStateIdStr] = [];
				}
				$newMap[$targetStateIdStr][$sourceMeta] = $sourceStateId;
			}
		}

		return $newMap;
	}

	/**
	 * Get the block mapping
	 * 
	 * @return array<string, array<int, int>> Map of source state IDs to target state IDs
	 */
	public static function get() : array{
		return self::$MAP;
	}

	/**
	 * Get a block from its state ID
	 * 
	 * @param int $stateId The block state ID
	 * @return Block|null The block, or null if not found
	 */
	public static function getBlockFromStateId(int $stateId) : ?Block{
		return RuntimeBlockStateRegistry::getInstance()->fromStateId($stateId);
	}
}
