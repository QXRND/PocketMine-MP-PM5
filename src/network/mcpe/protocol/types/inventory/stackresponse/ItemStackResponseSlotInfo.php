<?php

/*
 * This file is part of BedrockProtocol.
 * Copyright (C) 2014-2022 PocketMine Team <https://github.com/pmmp/BedrockProtocol>
 *
 * BedrockProtocol is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

declare(strict_types=1);

namespace pocketmine\network\mcpe\protocol\types\inventory\stackresponse;

use pmmp\encoding\Byte;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\VarInt;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;

final class ItemStackResponseSlotInfo{
	public function __construct(
		private int $slot,
		private int $hotbarSlot,
		private int $count,
		private int $itemStackId,
		private string $customName,
		private string $filteredCustomName,
		private int $durabilityCorrection
	){}

	public function getSlot() : int{ return $this->slot; }

	public function getHotbarSlot() : int{ return $this->hotbarSlot; }

	public function getCount() : int{ return $this->count; }

	public function getItemStackId() : int{ return $this->itemStackId; }

	public function getCustomName() : string{ return $this->customName; }

	public function getFilteredCustomName() : string{ return $this->filteredCustomName; }

	public function getDurabilityCorrection() : int{ return $this->durabilityCorrection; }

	public static function read(ByteBufferReader $in, int $protocolId) : self{
		$slot = Byte::readUnsigned($in);
		$hotbarSlot = Byte::readUnsigned($in);
		$count = Byte::readUnsigned($in);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			$itemStackId = CommonTypes::getBool($in) && CommonTypes::getBool($in) ? CommonTypes::readServerItemStackId($in) : 0;
		}else{
			$itemStackId = CommonTypes::readServerItemStackId($in);
		}
		$customName = CommonTypes::getString($in);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_21_50){
			$filteredCustomName = CommonTypes::getString($in);
		}
		$durabilityCorrection = VarInt::readSignedInt($in);
		return new self($slot, $hotbarSlot, $count, $itemStackId, $customName, $filteredCustomName ?? $customName, $durabilityCorrection);
	}

	public function write(ByteBufferWriter $out, int $protocolId) : void{
		Byte::writeUnsigned($out, $this->slot);
		Byte::writeUnsigned($out, $this->hotbarSlot);
		Byte::writeUnsigned($out, $this->count);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			CommonTypes::putBool($out, true); //redundant bool, always true
			$hasStackId = $this->itemStackId > 0;
			CommonTypes::putBool($out, $hasStackId);
			if($hasStackId){
				CommonTypes::writeServerItemStackId($out, $this->itemStackId);
			}
		}else{
			CommonTypes::writeServerItemStackId($out, $this->itemStackId);
		}
		//protocol >= 1.26.40 disconnects the client almost immediately after any
		//ItemStackResponse slot carries a non-empty customName (e.g. picking up a
		//renamed/custom-named item into the cursor) - confirmed by direct testing:
		//identical responses for unnamed items never trigger it, every named item
		//does, regardless of enchantments. The client already knows the item's real
		//name from its own NBT (display.Name, sent via the full item descriptor), so
		//this field is redundant for rendering - it exists for narrator/crossplay
		//chat-filter purposes, which we don't implement anyway (filteredCustomName
		//is always just a copy of customName in ItemStackResponseBuilder). Blanking
		//it here avoids the crash with no visible gameplay cost.
		$customName = $protocolId >= ProtocolInfo::PROTOCOL_1_26_40 ? "" : $this->customName;
		CommonTypes::putString($out, $customName);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_21_50){
			CommonTypes::putString($out, $protocolId >= ProtocolInfo::PROTOCOL_1_26_40 ? "" : $this->filteredCustomName);
		}
		VarInt::writeSignedInt($out, $this->durabilityCorrection);
	}
}
