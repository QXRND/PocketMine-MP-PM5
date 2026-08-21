<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\network\mcpe\convert;

use pocketmine\data\bedrock\block\BlockStateData;
use pocketmine\data\bedrock\block\BlockTypeNames;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\Tag;
use pocketmine\nbt\TreeRoot;
use pocketmine\utils\Utils;
use function count;
use function ksort;
use function ord;
use function strlen;
use const SORT_STRING;

final class BlockStateDictionaryEntry{
	/**
	 * Mojang special-cases minecraft:unknown instead of hashing it - see vanilla behaviour.
	 */
	private const HASHED_ID_UNKNOWN_BLOCK = -2;

	private const FNV1_32_INIT = 0x811c9dc5;
	private const FNV1_PRIME_32 = 0x01000193;

	/**
	 * @var string[]
	 * @phpstan-var array<string, string>
	 */
	private static array $uniqueRawStates = [];

	private string $rawStateProperties;

	/**
	 * @param Tag[] $stateProperties
	 * @phpstan-param array<string, Tag> $stateProperties
	 */
	public function __construct(
		private string $stateName,
		array $stateProperties,
		private int $meta,
		private ?BlockStateData $oldBlockStateData
	){
		$rawStateProperties = self::encodeStateProperties($stateProperties);
		$this->rawStateProperties = self::$uniqueRawStates[$rawStateProperties] ??= $rawStateProperties;
	}

	public function getStateName() : string{ return $this->stateName; }

	public function getRawStateProperties() : string{ return $this->rawStateProperties; }

	public function generateStateData() : BlockStateData{
		return $this->oldBlockStateData ?? $this->generateCurrentStateData();
	}

	public function generateCurrentStateData() : BlockStateData{
		return new BlockStateData(
			$this->stateName,
			self::decodeStateProperties($this->rawStateProperties),
			BlockStateData::CURRENT_VERSION
		);
	}

	public function getMeta() : int{ return $this->meta; }

	/**
	 * Computes the FNV1a-32 hash Mojang uses as the network block runtime ID for protocols >= 1.26.40
	 * ("block-network-ids-are-hashes"). The hash covers a canonical {name, states} NBT compound, written in
	 * little-endian disk NBT format (not network/varint format), with state properties sorted by key.
	 */
	public function computeNetworkStateHash() : int{
		if($this->stateName === BlockTypeNames::UNKNOWN){
			return self::HASHED_ID_UNKNOWN_BLOCK;
		}

		$tag = CompoundTag::create()
			->setString("name", $this->stateName)
			->setTag("states", $this->rawStateProperties === "" ?
				new CompoundTag() :
				(new LittleEndianNbtSerializer())->read($this->rawStateProperties)->mustGetCompoundTag()
			);

		$bytes = (new LittleEndianNbtSerializer())->write(new TreeRoot($tag));

		$hash = self::FNV1_32_INIT;
		for($i = 0, $len = strlen($bytes); $i < $len; ++$i){
			$hash ^= ord($bytes[$i]);
			$hash = ($hash * self::FNV1_PRIME_32) & 0xFFFFFFFF;
		}

		return $hash >= 0x80000000 ? $hash - 0x100000000 : $hash;
	}

	/**
	 * @return Tag[]
	 */
	public static function decodeStateProperties(string $rawProperties) : array{
		if($rawProperties === ""){
			return [];
		}
		return (new LittleEndianNbtSerializer())->read($rawProperties)->mustGetCompoundTag()->getValue();
	}

	/**
	 * @param Tag[] $properties
	 * @phpstan-param array<string, Tag> $properties
	 */
	public static function encodeStateProperties(array $properties) : string{
		if(count($properties) === 0){
			return "";
		}
		//TODO: make a more efficient encoding - NBT will do for now, but it's not very compact
		ksort($properties, SORT_STRING);
		$tag = new CompoundTag();
		foreach(Utils::stringifyKeys($properties) as $k => $v){
			$tag->setTag($k, $v);
		}
		return (new LittleEndianNbtSerializer())->write(new TreeRoot($tag));
	}
}
