<?php

declare(strict_types=1);

namespace matcracker\BlocksConverter\tasks;

use matcracker\BlocksConverter\commands\ToolBlock;
use pocketmine\block\Air;
use pocketmine\scheduler\Task;
use function count;

final class ToolBlockTask extends Task{

	public function onRun() : void{
		$players = ToolBlock::getPlayers();

		if(count($players) === 0){
			$this->getHandler()->cancel();

			return;
		}

		foreach($players as $player){
			$block = $player->getTargetBlock(5);
			if($block !== null && !($block instanceof Air)){
				$message = "{$block->getName()} (ID: {$block->getTypeId()} Meta: {$block->getDamage()})\n";
				$message .= "X: {$block->getPosition()->getX()} Y: {$block->getPosition()->getY()} Z: {$block->getPosition()->getZ()}";
				$player->sendTip($message);
			}
		}
	}
}
