<?php

declare(strict_types=1);

namespace pocketmine\redstone\piston;

use pocketmine\block\Block;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\StickyPiston;
use pocketmine\redstone\block\utils\BlockRedstoneUtils;

/**
 * Resolves piston movement without spawning an intermediate plugin entity.
 *
 * This deliberately performs an atomic state transfer: blocks are read first,
 * validated, then written from the far end towards the piston. This prevents
 * overwriting a block before its state has been copied and makes redstone
 * update recursion bounded and deterministic.
 */
final class PistonResolver{
	public const MAX_PUSHED_BLOCKS = 12;

	private function __construct(){ }

	public static function update(Block $piston) : void{
		$powered = BlockRedstoneUtils::hasPowerSourceNearby($piston);
		if($powered !== $piston->isPowered()){
			self::onPowerChanged($piston, $powered);
		}
	}

	public static function onPowerChanged(Block $piston, bool $powered) : void{
		$world = $piston->getPosition()->getWorld();
		if($piston->isPowered() === $powered){
			return;
		}
		$piston->setPowered($powered);
		$world->setBlock($piston->getPosition(), $piston, update: false);
		if($powered){
			self::extend($piston);
		}elseif($piston instanceof StickyPiston){
			self::retract($piston);
		}
	}

	private static function extend(Block $piston) : bool{
		$world = $piston->getPosition()->getWorld();
		$direction = $piston->getFacing();
		$origin = $piston->getPosition();
		$blocks = [];
		$cursor = $origin;

		for($i = 0; $i < self::MAX_PUSHED_BLOCKS; ++$i){
			$cursor = $cursor->getSide($direction);
			if(!$world->isInWorld($cursor->x, $cursor->y, $cursor->z)){
				return false;
			}
			$block = $world->getBlock($cursor);
			if($block->canBeReplaced()){
				break;
			}
			if(!$block->getBreakInfo()->isBreakable() || $world->getTile($cursor) !== null){
				return false;
			}
			$blocks[] = [$cursor, $block];
			if($i === self::MAX_PUSHED_BLOCKS - 1){
				return false;
			}
		}

		if(count($blocks) === 0){
			return true;
		}
		for($i = count($blocks) - 1; $i >= 0; --$i){
			[$source, $block] = $blocks[$i];
			$target = $source->getSide($direction);
			if(!$world->isInWorld($target->x, $target->y, $target->z)){
				return false;
			}
			$moved = RuntimeBlockStateRegistry::getInstance()->fromStateId($block->getStateId());
			$moved->position($world, $target->x, $target->y, $target->z);
			$world->setBlock($target, $moved, update: false);
			$world->setBlock($source, VanillaBlocks::AIR(), update: false);
		}
		return true;
	}

	private static function retract(StickyPiston $piston) : void{
		$world = $piston->getPosition()->getWorld();
		$direction = $piston->getFacing();
		$front = $piston->getPosition()->getSide($direction);
		$target = $front->getSide($direction);
		if(!$world->isInWorld($target->x, $target->y, $target->z)){
			return;
		}
		$block = $world->getBlock($target);
		if($block->canBeReplaced() || !$block->getBreakInfo()->isBreakable() || $world->getTile($target) !== null){
			return;
		}
		$moved = RuntimeBlockStateRegistry::getInstance()->fromStateId($block->getStateId());
		$moved->position($world, $front->x, $front->y, $front->z);
		$world->setBlock($front, $moved, update: false);
		$world->setBlock($target, VanillaBlocks::AIR(), update: false);
	}
}
