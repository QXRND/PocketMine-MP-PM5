<?php

declare(strict_types=1);

namespace pocketmine\redstone\component\power;

use pocketmine\block\Block;
use pocketmine\block\Button;
use pocketmine\block\Lever;
use pocketmine\block\Redstone;
use pocketmine\block\RedstoneTorch;
use pocketmine\block\RedstoneWire;
use pocketmine\block\SimplePressurePlate;
use pocketmine\redstone\block\power\BlockRedstonePowerHelper;
use pocketmine\redstone\block\transmission\BlockRedstoneTransmissionHelper;
use pocketmine\redstone\component\RedstoneComponent;
use pocketmine\math\Facing;
use pocketmine\world\World;

final class PowerComponent implements RedstoneComponent{
	public function __construct(private Block $block, private bool $active = true){
		if($block instanceof Lever){
			$this->active = $block->isActivated();
		}elseif($block instanceof RedstoneTorch){
			$this->active = $block->isLit();
		}
	}

	public function scheduleUpdate(int $delayTick = 1) : void{
		$block = $this->getBlock();
		$block->getPosition()->getWorld()->scheduleDelayedBlockUpdate($block->getPosition(), $delayTick);
	}

	public function handleComponents(int $action) : void{
		if($action !== self::ACTION_BREAK){
			return;
		}
		$block = $this->block;
		$visitedBlocks = [];
		foreach(Facing::ALL as $face){
			$neighbor = $block->getSide($face);
			if($neighbor instanceof RedstoneWire){
				$pos = $neighbor->getPosition();
				$visitedBlocks[World::blockHash($pos->x, $pos->y, $pos->z)] = true;
			}
		}
		BlockRedstoneTransmissionHelper::transmite($block, 0, $visitedBlocks);
	}

	public function getBlock() : Block{
		return $this->block;
	}

	public function isActivated() : bool{
		return $this->active;
	}

	public function getSignalPower() : int{
		$block = $this->getBlock();
		if($block instanceof Button || $block instanceof Redstone || $block instanceof SimplePressurePlate){
			return 15;
		}
		return $block instanceof RedstoneTorch && $block->isLit() ? 15 : 0;
	}
}
