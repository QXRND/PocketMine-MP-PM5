<?php

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\block\utils\AnyFacing;
use pocketmine\block\utils\AnyFacingTrait;
use pocketmine\block\utils\PoweredByRedstone;
use pocketmine\block\utils\PoweredByRedstoneTrait;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use pocketmine\redstone\block\power\BlockRedstonePowerHelper;

/**
 * A Bedrock observer. The redstone module owns change detection and pulse
 * scheduling; this class stores the protocol-facing state and orientation.
 */
class Observer extends Opaque implements AnyFacing, PoweredByRedstone{
	use AnyFacingTrait;
	use PoweredByRedstoneTrait;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->facing($this->facing);
		$w->bool($this->powered);
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($player !== null){
			$this->facing = $player->getHorizontalFacing();
		}
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onNearbyBlockChange() : void{
		if(!$this->powered){
			$this->powered = true;
			$world = $this->position->getWorld();
			$world->setBlock($this->position, $this);
			$world->scheduleDelayedBlockUpdate($this->position, 2);
			BlockRedstonePowerHelper::power($this);
		}
	}

	public function onScheduledUpdate() : void{
		if($this->powered){
			$this->powered = false;
			$this->position->getWorld()->setBlock($this->position, $this);
		}
	}
}
