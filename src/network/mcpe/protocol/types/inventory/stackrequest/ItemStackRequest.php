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

namespace pocketmine\network\mcpe\protocol\types\inventory\stackrequest;

use pmmp\encoding\Byte;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\DataDecodeException;
use pmmp\encoding\LE;
use pmmp\encoding\VarInt;
use pocketmine\network\mcpe\protocol\PacketDecodeException;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;
use function array_search;
use function count;

final class ItemStackRequest{

	/**
	 * Protocol >= 1.26.40 renumbered ItemStackRequestActionType entirely on the wire. This maps the wire ID used
	 * on that protocol to the (unchanged) internal ID constants used by ItemStackRequestActionType/the classes below.
	 * Bundle-related actions (PLACE_INTO_BUNDLE/TAKE_FROM_BUNDLE, internal IDs 7/8) have no known equivalent in the
	 * new numbering and are deliberately omitted.
	 */
	private const ACTION_TYPE_WIRE_TO_INTERNAL_1_26_40 = [
		0 => TakeStackRequestAction::ID,
		1 => PlaceStackRequestAction::ID,
		2 => SwapStackRequestAction::ID,
		3 => DropStackRequestAction::ID,
		4 => DestroyStackRequestAction::ID,
		5 => CraftingConsumeInputStackRequestAction::ID,
		6 => CraftingCreateSpecificResultStackRequestAction::ID,
		7 => LabTableCombineStackRequestAction::ID,
		8 => BeaconPaymentStackRequestAction::ID,
		9 => MineBlockStackRequestAction::ID,
		10 => CraftRecipeStackRequestAction::ID,
		11 => CraftRecipeAutoStackRequestAction::ID,
		12 => CreativeCreateStackRequestAction::ID,
		13 => CraftRecipeOptionalStackRequestAction::ID,
		14 => GrindstoneStackRequestAction::ID,
		15 => LoomStackRequestAction::ID,
		16 => DeprecatedCraftingNonImplementedStackRequestAction::ID,
		17 => DeprecatedCraftingResultsStackRequestAction::ID,
	];

	/**
	 * @param ItemStackRequestAction[] $actions
	 * @param string[]                 $filterStrings
	 * @phpstan-param list<string> $filterStrings
	 */
	public function __construct(
		private int $requestId,
		private array $actions,
		private array $filterStrings,
		private int $filterStringCause
	){}

	public function getRequestId() : int{ return $this->requestId; }

	/** @return ItemStackRequestAction[] */
	public function getActions() : array{ return $this->actions; }

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function getFilterStrings() : array{ return $this->filterStrings; }

	public function getFilterStringCause() : int{ return $this->filterStringCause; }

	/**
	 * @throws DataDecodeException
	 * @throws PacketDecodeException
	 */
	private static function readAction(ByteBufferReader $in, int $protocolId, int $typeId) : ItemStackRequestAction{
		return match($typeId){
			TakeStackRequestAction::ID => TakeStackRequestAction::read($in, $protocolId),
			PlaceStackRequestAction::ID => PlaceStackRequestAction::read($in, $protocolId),
			SwapStackRequestAction::ID => SwapStackRequestAction::read($in, $protocolId),
			DropStackRequestAction::ID => DropStackRequestAction::read($in, $protocolId),
			DestroyStackRequestAction::ID => DestroyStackRequestAction::read($in, $protocolId),
			CraftingConsumeInputStackRequestAction::ID => CraftingConsumeInputStackRequestAction::read($in, $protocolId),
			CraftingCreateSpecificResultStackRequestAction::ID => CraftingCreateSpecificResultStackRequestAction::read($in, $protocolId),
			PlaceIntoBundleStackRequestAction::ID => PlaceIntoBundleStackRequestAction::read($in, $protocolId),
			TakeFromBundleStackRequestAction::ID => TakeFromBundleStackRequestAction::read($in, $protocolId),
			LabTableCombineStackRequestAction::ID => LabTableCombineStackRequestAction::read($in, $protocolId),
			BeaconPaymentStackRequestAction::ID => BeaconPaymentStackRequestAction::read($in, $protocolId),
			MineBlockStackRequestAction::ID => MineBlockStackRequestAction::read($in, $protocolId),
			CraftRecipeStackRequestAction::ID => CraftRecipeStackRequestAction::read($in, $protocolId),
			CraftRecipeAutoStackRequestAction::ID => CraftRecipeAutoStackRequestAction::read($in, $protocolId),
			CreativeCreateStackRequestAction::ID => CreativeCreateStackRequestAction::read($in, $protocolId),
			CraftRecipeOptionalStackRequestAction::ID => CraftRecipeOptionalStackRequestAction::read($in, $protocolId),
			GrindstoneStackRequestAction::ID => GrindstoneStackRequestAction::read($in, $protocolId),
			LoomStackRequestAction::ID => LoomStackRequestAction::read($in, $protocolId),
			DeprecatedCraftingNonImplementedStackRequestAction::ID => DeprecatedCraftingNonImplementedStackRequestAction::read($in, $protocolId),
			DeprecatedCraftingResultsStackRequestAction::ID => DeprecatedCraftingResultsStackRequestAction::read($in, $protocolId),
			default => throw new PacketDecodeException("Unhandled item stack request action type $typeId"),
		};
	}

	public static function read(ByteBufferReader $in, int $protocolId) : self{
		$requestId = CommonTypes::readItemStackRequestId($in);
		$actions = [];
		for($i = 0, $len = VarInt::readUnsignedInt($in); $i < $len; ++$i){
			if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
				$wireTypeId = VarInt::readUnsignedInt($in);
				Byte::readUnsigned($in); //redundant legacy type byte
				$typeId = self::ACTION_TYPE_WIRE_TO_INTERNAL_1_26_40[$wireTypeId] ?? throw new PacketDecodeException("Unhandled item stack request action wire type $wireTypeId");
			}else{
				$typeId = Byte::readUnsigned($in);
			}
			$actions[] = self::readAction($in, $protocolId, $typeId);
		}
		$filterStrings = [];
		for($i = 0, $len = VarInt::readUnsignedInt($in); $i < $len; ++$i){
			$filterStrings[] = CommonTypes::getString($in);
		}
		$filterStringCause = LE::readSignedInt($in);
		return new self($requestId, $actions, $filterStrings, $filterStringCause);
	}

	public function write(ByteBufferWriter $out, int $protocolId) : void{
		CommonTypes::writeItemStackRequestId($out, $this->requestId);
		VarInt::writeUnsignedInt($out, count($this->actions));
		foreach($this->actions as $action){
			if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
				$wireTypeId = array_search($action->getTypeId(), self::ACTION_TYPE_WIRE_TO_INTERNAL_1_26_40, true);
				if($wireTypeId === false){
					throw new \InvalidArgumentException("No known wire type for item stack request action internal type " . $action->getTypeId() . " on protocol >= 1.26.40");
				}
				VarInt::writeUnsignedInt($out, $wireTypeId);
				Byte::writeUnsigned($out, $wireTypeId); //redundant legacy type byte
			}else{
				Byte::writeUnsigned($out, $action->getTypeId());
			}
			$action->write($out, $protocolId);
		}
		VarInt::writeUnsignedInt($out, count($this->filterStrings));
		foreach($this->filterStrings as $string){
			CommonTypes::putString($out, $string);
		}
		LE::writeSignedInt($out, $this->filterStringCause);
	}
}
