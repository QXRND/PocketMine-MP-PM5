<?php

declare(strict_types=1);

namespace pocketmine\redstone\hopper;

use pocketmine\block\Hopper;
use pocketmine\block\tile\Container;
use pocketmine\inventory\Inventory;
use pocketmine\item\Item;
use pocketmine\math\Facing;

final class HopperTransfer{
	private function __construct(){ }

	public static function tick(Hopper $hopper) : bool{
		$world = $hopper->getPosition()->getWorld();
		$tile = $world->getTile($hopper->getPosition());
		if(!$tile instanceof Container){
			return false;
		}
		$inventory = $tile->getRealInventory();
		$changed = false;

		$targetTile = $world->getTile($hopper->getPosition()->getSide($hopper->getFacing()));
		if($targetTile instanceof Container){
			$target = $targetTile->getRealInventory();
			$changed = self::pushOne($inventory, $target);
		}
		if(!$changed && $hopper->getFacing() !== Facing::UP){
			$sourceTile = $world->getTile($hopper->getPosition()->up());
			if($sourceTile instanceof Container){
				$changed = self::pullOne($sourceTile->getRealInventory(), $inventory);
			}
		}
		return $changed;
	}

	private static function pushOne(Inventory $source, Inventory $target) : bool{
		foreach($source->getContents() as $slot => $item){
			if($item->isNull()){
				continue;
			}
			$one = $item->pop();
			if(!$target->canAddItem($one)){
				continue;
			}
			$target->addItem($one);
			$source->setItem($slot, $item);
			return true;
		}
		return false;
	}

	private static function pullOne(Inventory $source, Inventory $target) : bool{
		foreach($source->getContents() as $slot => $item){
			if($item->isNull()){
				continue;
			}
			$one = $item->pop();
			if(!$target->canAddItem($one)){
				continue;
			}
			$target->addItem($one);
			$source->setItem($slot, $item);
			return true;
		}
		return false;
	}
}
