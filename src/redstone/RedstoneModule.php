<?php
declare(strict_types=1);

namespace pocketmine\redstone;

use pocketmine\block\Block;
use pocketmine\world\World;
use pocketmine\YmlServerProperties as Yml;
use pocketmine\redstone\block\power\BlockRedstonePowerHelper;
use pocketmine\redstone\block\transmission\BlockRedstoneTransmissionHelper;
use pocketmine\redstone\block\utils\BlockRedstoneUtils;

/**
 * Internal redstone coordinator for QXRND PocketMine-MP.
 * This is a core module, not an externally loaded plugin.
 */
final class RedstoneModule{
	private static int $lastTick = -1;
	/** @var array<string, true> */
	private static array $processed = [];

	private function __construct(){}

	public static function isEnabled(World $world) : bool{
		return $world->getServer()->getConfigGroup()->getPropertyBool(Yml::REDSTONE_ENABLED, true);
	}

	public static function processBlockUpdate(Block $block) : void{
		$world = $block->getPosition()->getWorld();
		if(!self::isEnabled($world)){
			return;
		}
		$tick = $world->getServer()->getTick();
		if(self::$lastTick !== $tick){
			self::$lastTick = $tick;
			self::$processed = [];
		}
		$key = spl_object_id($world) . ':' . World::blockHash($block->getPosition()->x, $block->getPosition()->y, $block->getPosition()->z);
		if(isset(self::$processed[$key])){
			return;
		}
		self::$processed[$key] = true;
		if(BlockRedstoneUtils::isPowerComponent($block)){
			BlockRedstonePowerHelper::update($block);
		}elseif(BlockRedstoneUtils::isTransmissionComponent($block)){
			BlockRedstoneTransmissionHelper::update($block);
		}
	}
}
