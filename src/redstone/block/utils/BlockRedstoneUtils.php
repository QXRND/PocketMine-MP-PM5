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
 * @author  pocketmine
 * @link    https://github.com/pocketmine/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\redstone\block\utils;

use pocketmine\redstone\block\power\BlockRedstonePowerHelper;
use pocketmine\block\Block;
use pocketmine\block\Button;
use pocketmine\block\Lever;
use pocketmine\block\Redstone;
use pocketmine\block\RedstoneComparator;
use pocketmine\block\RedstoneRepeater;
use pocketmine\block\RedstoneTorch;
use pocketmine\block\RedstoneWire;
use pocketmine\block\Observer;
use pocketmine\block\SimplePressurePlate;
use pocketmine\block\WeightedPressurePlate;
use pocketmine\math\Facing;
use pocketmine\world\World;
use ReflectionClass;
use function in_array;

final class BlockRedstoneUtils{

	public static function isPoweredByRedstone(Block $block) : bool{
		$reflectionClass = new ReflectionClass($block);
		return in_array("pocketmine\block\utils\PoweredByRedstoneTrait", $reflectionClass->getTraitNames(), true);
	}

	public static function isPowerComponent(Block $block) : bool{
		if($block instanceof Button || $block instanceof Lever || $block instanceof Redstone || $block instanceof RedstoneTorch || $block instanceof SimplePressurePlate || $block instanceof WeightedPressurePlate || $block instanceof Observer){
			//TODO: support more blocks ?
			return true;
		}
		return false;
	}

	public static function isTransmissionComponent(Block $block) : bool{
		if($block instanceof RedstoneWire || $block instanceof RedstoneRepeater){
			//TODO: support more blocks ?
			return true;
		}
		return false;
	}

	public static function hasPowerSourceNearby(Block $block, array &$visitedBlocks = []) : bool{
		return self::getInputSignalStrength($block, $visitedBlocks) > 0;
	}

	public static function getInputSignalStrength(Block $block, array &$visitedBlocks = []) : int{
		$pos = $block->getPosition();
		$world = $pos->getWorld();
		$hash = World::blockHash($pos->x, $pos->y, $pos->z);
		if(isset($visitedBlocks[$hash])){
			return 0;
		}
		$visitedBlocks[$hash] = true;
		$max = 0;

		foreach(Facing::ALL as $face){
			$neighbor = $block->getSide($face);
			$signal = 0;
			if($neighbor instanceof RedstoneWire){
				$signal = $neighbor->getOutputSignalStrength();
			}elseif($neighbor instanceof Lever){
				$signal = $neighbor->isActivated() ? 15 : 0;
			}elseif($neighbor instanceof Button){
				$signal = $neighbor->isPressed() ? 15 : 0;
			}elseif($neighbor instanceof RedstoneTorch){
				$signal = $neighbor->isLit() ? 15 : 0;
			}elseif($neighbor instanceof Redstone){
				$signal = 15;
			}elseif($neighbor instanceof Observer){
				$signal = $neighbor->isPowered() ? 15 : 0;
			}elseif($neighbor instanceof SimplePressurePlate || $neighbor instanceof WeightedPressurePlate){
				$signal = $neighbor->getOutputSignalStrength();
			}
			$max = max($max, $signal);
		}
		return $max;
	}
}
