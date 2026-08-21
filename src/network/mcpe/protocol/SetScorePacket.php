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
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;
use pocketmine\network\mcpe\protocol\types\ScorePacketEntry;
use function count;

class SetScorePacket extends DataPacket implements ClientboundPacket{
	public const NETWORK_ID = ProtocolInfo::SET_SCORE_PACKET;

	public const TYPE_CHANGE = 0;
	public const TYPE_REMOVE = 1;

	public int $type;
	/** @var ScorePacketEntry[] */
	public array $entries = [];

	/**
	 * @generate-create-func
	 * @param ScorePacketEntry[] $entries
	 */
	public static function create(int $type, array $entries) : self{
		$result = new self;
		$result->type = $type;
		$result->entries = $entries;
		return $result;
	}

	//protocol >= 1.26.40 rewrote SetScorePacket entirely: every entry now carries its own type (as both a
	//VarInt ordinal and a redundant string name) instead of one type byte for the whole packet, "remove"
	//entries became a distinct INVALID entry type with no score/player data (just an optional objective
	//name), and empty objective/name strings must be substituted with a single space or the client rejects
	//the packet.
	private const WIRE_TYPE_NAMES_1_26_40 = ["remove", "changeplayer", "changeentity", "changefakeplayer"];

	protected function decodePayload(ByteBufferReader $in, int $protocolId) : void{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			$count = VarInt::readUnsignedInt($in);
			for($i = 0; $i < $count; ++$i){
				$wireType = VarInt::readUnsignedInt($in);
				CommonTypes::getString($in); //redundant type name, ignored
				$entry = new ScorePacketEntry();
				$entry->type = $wireType;
				$entry->scoreboardId = VarInt::readSignedLong($in);
				if($wireType === ScorePacketEntry::TYPE_INVALID){
					$entry->objectiveName = CommonTypes::getBool($in) ? CommonTypes::getString($in) : "";
					$entry->score = 0;
				}else{
					$entry->objectiveName = CommonTypes::getString($in);
					$entry->score = LE::readSignedInt($in);
					switch($wireType){
						case ScorePacketEntry::TYPE_PLAYER:
						case ScorePacketEntry::TYPE_ENTITY:
							$entry->actorUniqueId = CommonTypes::getActorUniqueId($in);
							break;
						case ScorePacketEntry::TYPE_FAKE_PLAYER:
							$entry->customName = CommonTypes::getString($in);
							break;
						default:
							throw new PacketDecodeException("Unknown entry type $wireType");
					}
				}
				$this->entries[] = $entry;
			}
			$this->type = (count($this->entries) > 0 && $this->entries[0]->type === ScorePacketEntry::TYPE_INVALID) ? self::TYPE_REMOVE : self::TYPE_CHANGE;
			return;
		}

		$this->type = Byte::readUnsigned($in);
		for($i = 0, $i2 = VarInt::readUnsignedInt($in); $i < $i2; ++$i){
			$entry = new ScorePacketEntry();
			$entry->scoreboardId = VarInt::readSignedLong($in);
			$entry->objectiveName = CommonTypes::getString($in);
			$entry->score = LE::readSignedInt($in);
			if($this->type !== self::TYPE_REMOVE){
				$entry->type = Byte::readUnsigned($in);
				switch($entry->type){
					case ScorePacketEntry::TYPE_PLAYER:
					case ScorePacketEntry::TYPE_ENTITY:
						$entry->actorUniqueId = CommonTypes::getActorUniqueId($in);
						break;
					case ScorePacketEntry::TYPE_FAKE_PLAYER:
						$entry->customName = CommonTypes::getString($in);
						break;
					default:
						throw new PacketDecodeException("Unknown entry type $entry->type");
				}
			}
			$this->entries[] = $entry;
		}
	}

	protected function encodePayload(ByteBufferWriter $out, int $protocolId) : void{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			VarInt::writeUnsignedInt($out, count($this->entries));
			foreach($this->entries as $entry){
				$wireType = $this->type === self::TYPE_REMOVE ? ScorePacketEntry::TYPE_INVALID : $entry->type;
				VarInt::writeUnsignedInt($out, $wireType);
				CommonTypes::putString($out, self::WIRE_TYPE_NAMES_1_26_40[$wireType]);
				VarInt::writeSignedLong($out, $entry->scoreboardId);
				if($wireType === ScorePacketEntry::TYPE_INVALID){
					$hasObjective = $entry->objectiveName !== "";
					CommonTypes::putBool($out, $hasObjective);
					if($hasObjective){
						CommonTypes::putString($out, $entry->objectiveName);
					}
				}else{
					CommonTypes::putString($out, $entry->objectiveName !== "" ? $entry->objectiveName : " ");
					LE::writeSignedInt($out, $entry->score);
					switch($wireType){
						case ScorePacketEntry::TYPE_PLAYER:
						case ScorePacketEntry::TYPE_ENTITY:
							CommonTypes::putActorUniqueId($out, $entry->actorUniqueId);
							break;
						case ScorePacketEntry::TYPE_FAKE_PLAYER:
							$name = $entry->customName ?? "";
							CommonTypes::putString($out, $name !== "" ? $name : " ");
							break;
						default:
							throw new \InvalidArgumentException("Unknown entry type $wireType");
					}
				}
			}
			return;
		}

		Byte::writeUnsigned($out, $this->type);
		VarInt::writeUnsignedInt($out, count($this->entries));
		foreach($this->entries as $entry){
			VarInt::writeSignedLong($out, $entry->scoreboardId);
			CommonTypes::putString($out, $entry->objectiveName);
			LE::writeSignedInt($out, $entry->score);
			if($this->type !== self::TYPE_REMOVE){
				Byte::writeUnsigned($out, $entry->type);
				switch($entry->type){
					case ScorePacketEntry::TYPE_PLAYER:
					case ScorePacketEntry::TYPE_ENTITY:
						CommonTypes::putActorUniqueId($out, $entry->actorUniqueId);
						break;
					case ScorePacketEntry::TYPE_FAKE_PLAYER:
						CommonTypes::putString($out, $entry->customName);
						break;
					default:
						throw new \InvalidArgumentException("Unknown entry type $entry->type");
				}
			}
		}
	}

	public function handle(PacketHandlerInterface $handler) : bool{
		return $handler->handleSetScore($this);
	}
}
