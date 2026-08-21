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

namespace pocketmine\network\mcpe\protocol;

use pmmp\encoding\Byte;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\LE;
use pmmp\encoding\VarInt;
use pocketmine\color\Color;
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;
use pocketmine\network\mcpe\protocol\types\PlayerListEntry;
use function count;

class PlayerListPacket extends DataPacket implements ClientboundPacket{
	public const NETWORK_ID = ProtocolInfo::PLAYER_LIST_PACKET;

	public const TYPE_ADD = 0;
	public const TYPE_REMOVE = 1;

	public int $type;
	/** @var PlayerListEntry[] */
	public array $entries = [];

	/**
	 * @generate-create-func
	 * @param PlayerListEntry[] $entries
	 */
	private static function create(int $type, array $entries) : self{
		$result = new self;
		$result->type = $type;
		$result->entries = $entries;
		return $result;
	}

	/**
	 * @param PlayerListEntry[] $entries
	 */
	public static function add(array $entries) : self{
		return self::create(self::TYPE_ADD, $entries);
	}

	/**
	 * @param PlayerListEntry[] $entries
	 */
	public static function remove(array $entries) : self{
		return self::create(self::TYPE_REMOVE, $entries);
	}

	private function decodeEntryBody(ByteBufferReader $in, PlayerListEntry $entry, int $protocolId) : void{
		if($entry->type === self::TYPE_ADD){
			$entry->uuid = CommonTypes::getUUID($in);
			$entry->actorUniqueId = CommonTypes::getActorUniqueId($in);
			$entry->username = CommonTypes::getString($in);
			$entry->xboxUserId = CommonTypes::getString($in);
			$entry->platformChatId = CommonTypes::getString($in);
			$entry->buildPlatform = LE::readSignedInt($in);
			$entry->skinData = CommonTypes::getSkin($in, $protocolId);
			$entry->isTeacher = CommonTypes::getBool($in);
			$entry->isHost = CommonTypes::getBool($in);
			if($protocolId >= ProtocolInfo::PROTOCOL_1_20_60){
				$entry->isSubClient = CommonTypes::getBool($in);
				if($protocolId >= ProtocolInfo::PROTOCOL_1_21_80){
					$entry->color = Color::fromARGB(LE::readUnsignedInt($in));
				}
			}
		}else{
			$entry->uuid = CommonTypes::getUUID($in);
		}
	}

	private function encodeEntryBody(ByteBufferWriter $out, PlayerListEntry $entry, int $protocolId) : void{
		if($entry->type === self::TYPE_ADD){
			CommonTypes::putUUID($out, $entry->uuid);
			CommonTypes::putActorUniqueId($out, $entry->actorUniqueId);
			$username = $entry->username;
			if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40 && $username === ""){
				//protocol >= 1.26.40 silently disconnects the client if a PlayerListPacket ADD entry has an
				//empty username; some NPC plugins send an empty username here as a workaround to rebind a
				//custom-geometry skin to the client without showing a real name in the tab list
				$username = " ";
			}
			CommonTypes::putString($out, $username);
			CommonTypes::putString($out, $entry->xboxUserId);
			CommonTypes::putString($out, $entry->platformChatId);
			LE::writeSignedInt($out, $entry->buildPlatform);
			CommonTypes::putSkin($out, $entry->skinData, $protocolId);
			CommonTypes::putBool($out, $entry->isTeacher);
			CommonTypes::putBool($out, $entry->isHost);
			if($protocolId >= ProtocolInfo::PROTOCOL_1_20_60){
				CommonTypes::putBool($out, $entry->isSubClient);
				if($protocolId >= ProtocolInfo::PROTOCOL_1_21_80){
					LE::writeUnsignedInt($out, ($entry->color ?? new Color(255, 255, 255))->toARGB());
				}
			}
		}else{
			CommonTypes::putUUID($out, $entry->uuid);
		}
	}

	protected function decodePayload(ByteBufferReader $in, int $protocolId) : void{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			//protocol >= 1.26.40 carries the add/remove type per-entry instead of once for the whole packet, and
			//also writes a redundant "legacy" byte using the old (pre-1.26.40) TYPE_* numbering
			$count = VarInt::readUnsignedInt($in);
			for($i = 0; $i < $count; ++$i){
				$entry = new PlayerListEntry();
				$wireType = VarInt::readUnsignedInt($in);
				$entry->type = $wireType === 1 ? self::TYPE_ADD : self::TYPE_REMOVE;
				Byte::readUnsigned($in); //legacy type byte, redundant with $wireType
				$this->decodeEntryBody($in, $entry, $protocolId);
				$this->entries[$i] = $entry;
			}
			$this->type = $count > 0 ? $this->entries[0]->type : self::TYPE_ADD;
			return;
		}

		$this->type = Byte::readUnsigned($in);
		$count = VarInt::readUnsignedInt($in);
		for($i = 0; $i < $count; ++$i){
			$entry = new PlayerListEntry();
			$entry->type = $this->type;
			$this->decodeEntryBody($in, $entry, $protocolId);
			$this->entries[$i] = $entry;
		}
		if($this->type === self::TYPE_ADD){
			for($i = 0; $i < $count; ++$i){
				$this->entries[$i]->skinData->setVerified(CommonTypes::getBool($in));
			}
		}
	}

	protected function encodePayload(ByteBufferWriter $out, int $protocolId) : void{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			VarInt::writeUnsignedInt($out, count($this->entries));
			foreach($this->entries as $entry){
				VarInt::writeUnsignedInt($out, $entry->type === self::TYPE_ADD ? 1 : 0);
				Byte::writeUnsigned($out, $entry->type); //legacy type byte (TYPE_ADD=0/TYPE_REMOVE=1 numbering)
				$this->encodeEntryBody($out, $entry, $protocolId);
			}
			return;
		}

		Byte::writeUnsigned($out, $this->type);
		VarInt::writeUnsignedInt($out, count($this->entries));
		foreach($this->entries as $entry){
			$this->encodeEntryBody($out, $entry, $protocolId);
		}
		if($this->type === self::TYPE_ADD){
			foreach($this->entries as $entry){
				CommonTypes::putBool($out, $entry->skinData->isVerified());
			}
		}
	}

	public function handle(PacketHandlerInterface $handler) : bool{
		return $handler->handlePlayerList($this);
	}
}
