<?php
declare(strict_types=1);

namespace pocketmine\redstone;

use pocketmine\block\Block;
use pocketmine\redstone\block\power\BlockRedstonePowerHelper;
use pocketmine\redstone\block\transmission\BlockRedstoneTransmissionHelper;
use pocketmine\redstone\block\utils\BlockRedstoneUtils;

/**
 * Internal redstone coordinator for QXRND PocketMine-MP.
 * This is a core module, not an externally loaded plugin.
 */
final class RedstoneModule{
	private function __construct(){}

	public static function processBlockUpdate(Block $block) : void{
		if(BlockRedstoneUtils::isPowerComponent($block)){
			BlockRedstonePowerHelper::update($block);
		}elseif(BlockRedstoneUtils::isTransmissionComponent($block)){
			BlockRedstoneTransmissionHelper::update($block);
		}
	}
}
