<?php

/*
 * Copyright (c) 2024 - present pocketmine
 *        _      _           _                ___   ___ ____
 *       (_)    | |         | |              / _ \ / _ \___ \
 *  _ __  _  ___| |__   ___ | | __ _ ___ ___| | | | | | |__) |
 * | '_ \| |/ __| '_ \ / _ \| |/ _` / __/ __| | | | | | |__ <
 * | | | | | (__| | | | (_) | | (_| \__ \__ \ |_| | |_| |__) |
 * |_| |_|_|\___|_| |_|\___/|_|\__,_|___/___/\___/ \___/____/
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author  DevPapo
 * @link    https://github.com/pocketmine/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\redstone\block\power;

use pocketmine\redstone\block\IBlockRedstoneHelper;
use pocketmine\redstone\block\transmission\BlockRedstoneTransmissionHelper;
use pocketmine\redstone\block\utils\BlockRedstoneUtils;
use pocketmine\redstone\component\power\PowerComponent;
use pocketmine\redstone\event\BlockRedstonePowerEvent;
use pocketmine\redstone\piston\PistonResolver;
use pocketmine\block\Piston;
use pocketmine\block\Block;
use pocketmine\block\Button;
use pocketmine\block\Lever;
use pocketmine\block\Redstone;
use pocketmine\block\RedstoneTorch;
use pocketmine\block\RedstoneWire;
use pocketmine\block\RedstoneRepeater;
use pocketmine\block\Observer;
use pocketmine\block\Dispenser;
use pocketmine\block\Dropper;
use pocketmine\block\SimplePressurePlate;
use pocketmine\block\WeightedPressurePlate;
use pocketmine\block\WoodenButton;
use pocketmine\block\utils\AnalogRedstoneSignalEmitter;
use pocketmine\block\utils\PoweredByRedstone;
use pocketmine\math\Facing;
use pocketmine\world\World;

class BlockRedstonePowerHelper implements IBlockRedstoneHelper{

	public static function update(Block $block) : void{
		$visitedBlocks = [];
		self::power($block, $visitedBlocks);
	}

	/** @param array<int, true> $visitedBlocks */
	public static function power(Block $block, array &$visitedBlocks = []) : void{
		$activate = false;
		$component = null;
		$ignoreFace = null;
		$power = 0;
		if($block instanceof Lever){
			$activate = $block->isActivated();
			$ignoreFace = $block->getFacing()->getFacing();
			if($activate === true){
				$power = 15;
			}
		}elseif($block instanceof Redstone){
			$power = 15;
			$activate = true;
		}elseif($block instanceof Button){
			$power = 15;
			$activate = $block->isPressed();
			$redstoneTicks = 10; //StoneButton
			if($block instanceof WoodenButton){
				$redstoneTicks = 15;
			}
			$component = new PowerComponent($block);
			$component->scheduleUpdate($redstoneTicks);
		}elseif($block instanceof RedstoneTorch){
			$activate = $block->isLit();
			if($activate === true){
				$power = 15;
			}
		}elseif($block instanceof Observer){
			$activate = $block->isPowered();
			if($activate){
				$power = 15;
			}
		}elseif($block instanceof RedstoneRepeater){
			$activate = $block->isPowered();
			$power = 15;
		}elseif($block instanceof AnalogRedstoneSignalEmitter){
			$power = $block->getOutputSignalStrength();
			$activate = $power > 0;
		}elseif($block instanceof SimplePressurePlate){
			$power = $block->isPressed() ? 15 : 0;
			$activate = $power > 0;
		}
		foreach(Facing::ALL as $face){
			if($block instanceof Observer && $face !== Facing::opposite($block->getFacing())){
				continue;
			}
			if($block instanceof RedstoneRepeater && $face !== $block->getFacing()){
				continue;
			}
			if($face === $ignoreFace){
				continue;
			}
			$rBlock = $block->getSide($face);
			$world = $rBlock->getPosition()->getWorld();
			if($rBlock instanceof RedstoneWire){
				$wirePower = $activate ? $power : 0;
				if($rBlock->getOutputSignalStrength() !== $wirePower){
					$rBlock->setOutputSignalStrength($wirePower);
					$world->setBlock($rBlock->getPosition(), $rBlock, update: false);
					BlockRedstoneTransmissionHelper::update($rBlock);
				}
			}else{
				self::activate($rBlock, $activate, $visitedBlocks);
			}
		}
	}

	/** @param array<int, true> $visitedBlocks */
	public static function activate(Block $block, bool $activate, array &$visitedBlocks = []) : void{
		$pos = $block->getPosition();
		$world = $pos->getWorld();

			$hash = World::blockHash((int) $pos->x, (int) $pos->y, (int) $pos->z);
			if(isset($visitedBlocks[$hash])){
			return;
		}
		$visitedBlocks[$hash] = true;

		if($block instanceof PoweredByRedstone){
			$ev = new BlockRedstonePowerEvent($block, $activate);
			$ev->call();
			$powered = $ev->getPowered();
				if($block->isPowered() !== $powered){
					if($block instanceof Piston){
						PistonResolver::onPowerChanged($block, $powered);
					}else{
						$block->setPowered($powered);
						$world->setBlock($pos, $block, update: false);
						if($powered && ($block instanceof Dispenser || $block instanceof Dropper)){
							$world->scheduleDelayedBlockUpdate($pos, 1);
						}
					}
				}
		}
	}
}
