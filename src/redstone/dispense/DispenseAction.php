<?php

declare(strict_types=1);

namespace pocketmine\redstone\dispense;

use pocketmine\block\Block;
use pocketmine\block\Dropper;
use pocketmine\block\tile\Dispenser as TileDispenser;
use pocketmine\block\tile\Dropper as TileDropper;
use pocketmine\item\Item;
use pocketmine\block\utils\AnyFacing;

final class DispenseAction{
	private function __construct(){ }

	public static function execute(Block $block) : bool{
		if(!$block instanceof AnyFacing){
			return false;
		}
		$world = $block->getPosition()->getWorld();
		$tile = $world->getTile($block->getPosition());
		$inventory = match(true){
			$tile instanceof TileDispenser => $tile->getInventory(),
			$tile instanceof TileDropper => $tile->getInventory(),
			default => null
		};
		if($inventory === null){
			return false;
		}

		$available = [];
		foreach($inventory->getContents() as $slot => $item){
			if($item instanceof Item && !$item->isNull() && $item->getCount() > 0){
				$available[] = $slot;
			}
		}
		if($available === []){
			return false;
		}

		$slot = $available[array_rand($available)];
		$item = $inventory->getItem($slot);
		$dispensed = $item->pop();
		$inventory->setItem($slot, $item);
		if($dispensed->isNull()){
			return false;
		}
		$front = $block->getPosition()->getSide($block->getFacing());
		$world->dropItem($front->add(0.5, 0.5, 0.5), $dispensed);
		return true;
	}
}
