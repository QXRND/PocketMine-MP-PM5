<?php

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\block\tile\Dispenser as TileDispenser;
use pocketmine\block\utils\AnyFacing;
use pocketmine\block\utils\AnyFacingTrait;
use pocketmine\block\utils\PoweredByRedstone;
use pocketmine\block\utils\PoweredByRedstoneTrait;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use pocketmine\redstone\dispense\DispenseAction;

class Dispenser extends Opaque implements AnyFacing, PoweredByRedstone{
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

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($player !== null){
			$tile = $this->position->getWorld()->getTile($this->position);
			if($tile instanceof TileDispenser){
				$player->setCurrentWindow($tile->getInventory());
			}
		}
		return true;
	}

	public function onScheduledUpdate() : void{
		if($this->powered){
			DispenseAction::execute($this);
			$this->powered = false;
			$this->position->getWorld()->setBlock($this->position, $this);
		}
	}
}
