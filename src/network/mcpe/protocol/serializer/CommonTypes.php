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

namespace pocketmine\network\mcpe\protocol\serializer;

use pmmp\encoding\Byte;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\DataDecodeException;
use pmmp\encoding\LE;
use pmmp\encoding\VarInt;
use pocketmine\math\Vector2;
use pocketmine\math\Vector3;
use pocketmine\nbt\NbtDataException;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\TreeRoot;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\PacketDecodeException;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\BoolGameRule;
use pocketmine\network\mcpe\protocol\types\command\CommandOriginData;
use pocketmine\network\mcpe\protocol\types\entity\BlockPosMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\ByteMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\CompoundTagMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\EntityLink;
use pocketmine\network\mcpe\protocol\types\entity\FloatMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\IntMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\LongMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\MetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\ShortMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\StringMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\Vec3MetadataProperty;
use pocketmine\network\mcpe\protocol\types\FloatGameRule;
use pocketmine\network\mcpe\protocol\types\GameRule;
use pocketmine\network\mcpe\protocol\types\IntGameRule;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStack;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\network\mcpe\protocol\types\recipe\ComplexAliasItemDescriptor;
use pocketmine\network\mcpe\protocol\types\recipe\IntIdMetaItemDescriptor;
use pocketmine\network\mcpe\protocol\types\recipe\ItemDescriptorType;
use pocketmine\network\mcpe\protocol\types\recipe\MolangItemDescriptor;
use pocketmine\network\mcpe\protocol\types\recipe\RecipeIngredient;
use pocketmine\network\mcpe\protocol\types\recipe\StringIdMetaItemDescriptor;
use pocketmine\network\mcpe\protocol\types\recipe\TagItemDescriptor;
use pocketmine\network\mcpe\protocol\types\skin\PersonaPieceTintColor;
use pocketmine\network\mcpe\protocol\types\skin\PersonaSkinPiece;
use pocketmine\network\mcpe\protocol\types\skin\SkinAnimation;
use pocketmine\network\mcpe\protocol\types\skin\SkinData;
use pocketmine\network\mcpe\protocol\types\skin\SkinImage;
use pocketmine\network\mcpe\protocol\types\StructureEditorData;
use pocketmine\network\mcpe\protocol\types\StructureSettings;
use pocketmine\utils\Binary;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use function array_flip;
use function count;
use function dechex;
use function hexdec;
use function ltrim;
use function str_pad;
use function strlen;
use function strrev;
use function substr;
use const STR_PAD_LEFT;

final class CommonTypes{

	private function __construct(){
		//NOOP
	}

	/** @throws DataDecodeException */
	public static function getString(ByteBufferReader $in) : string{
		return $in->readByteArray(VarInt::readUnsignedInt($in));
	}

	public static function putString(ByteBufferWriter $out, string $v) : void{
		VarInt::writeUnsignedInt($out, strlen($v));
		$out->writeByteArray($v);
	}

	/** @throws DataDecodeException */
	public static function getBool(ByteBufferReader $in) : bool{
		return Byte::readUnsigned($in) !== 0;
	}

	public static function putBool(ByteBufferWriter $out, bool $v) : void{
		Byte::writeUnsigned($out, $v ? 1 : 0);
	}

	/** @throws DataDecodeException */
	public static function getUUID(ByteBufferReader $in) : UuidInterface{
		//This is two little-endian longs: bytes 7-0 followed by bytes 15-8
		$p1 = strrev($in->readByteArray(8));
		$p2 = strrev($in->readByteArray(8));
		return Uuid::fromBytes($p1 . $p2);
	}

	public static function putUUID(ByteBufferWriter $out, UuidInterface $uuid) : void{
		$bytes = $uuid->getBytes();
		$out->writeByteArray(strrev(substr($bytes, 0, 8)));
		$out->writeByteArray(strrev(substr($bytes, 8, 8)));
	}

	/**
	 * Reads a network block runtime ID. For protocols >= 1.26.40, this is an FNV1a-32 hash which may be negative
	 * as a signed 32-bit value; VarInt::readUnsignedInt() doesn't sign-extend, so we normalize it here to match
	 * what BlockStateDictionary produces/expects (see computeNetworkStateHash()).
	 * @throws DataDecodeException
	 */
	public static function getBlockRuntimeId(ByteBufferReader $in) : int{
		$value = VarInt::readUnsignedInt($in);
		return $value >= 0x80000000 ? $value - 0x100000000 : $value;
	}

	public static function putBlockRuntimeId(ByteBufferWriter $out, int $value) : void{
		VarInt::writeUnsignedInt($out, $value);
	}

	/** @throws DataDecodeException */
	public static function getSkin(ByteBufferReader $in, int $protocolId) : SkinData{
		$hashedIds = $protocolId >= ProtocolInfo::PROTOCOL_1_26_40;

		$skinId = self::getString($in);
		$skinPlayFabId = self::getString($in);
		$skinResourcePatch = self::getString($in);
		$skinData = self::getSkinImage($in);
		$animationCount = $hashedIds ? VarInt::readUnsignedInt($in) : LE::readUnsignedInt($in);
		$animations = [];
		for($i = 0; $i < $animationCount; ++$i){
			$skinImage = self::getSkinImage($in);
			$animationType = $hashedIds ? VarInt::readUnsignedInt($in) : LE::readUnsignedInt($in);
			$animationFrames = LE::readFloat($in);
			$expressionType = $hashedIds ? VarInt::readUnsignedInt($in) : LE::readUnsignedInt($in);
			$animations[] = new SkinAnimation($skinImage, $animationType, $animationFrames, $expressionType);
		}
		$capeData = self::getSkinImage($in);
		$geometryData = self::getString($in);
		$geometryDataVersion = self::getString($in);
		$animationData = self::getString($in);
		$capeId = self::getString($in);
		$fullSkinId = self::getString($in);
		if($hashedIds){
			$armSize = Byte::readUnsigned($in) === 0 ? SkinData::ARM_SIZE_SLIM : SkinData::ARM_SIZE_WIDE;
			$skinColor = self::decodeHexColor(LE::readUnsignedInt($in));
		}else{
			$armSize = self::getString($in);
			$skinColor = self::getString($in);
		}
		$personaPieceCount = $hashedIds ? VarInt::readUnsignedInt($in) : LE::readUnsignedInt($in);
		$personaPieces = [];
		for($i = 0; $i < $personaPieceCount; ++$i){
			$pieceId = self::getString($in);
			if($hashedIds){
				$pieceType = self::personaPieceTypeFromInt(LE::readSignedInt($in));
				$packId = self::getUUID($in)->toString();
			}else{
				$pieceType = self::getString($in);
				$packId = self::getString($in);
			}
			$isDefaultPiece = self::getBool($in);
			$productId = self::getString($in);
			$personaPieces[] = new PersonaSkinPiece($pieceId, $pieceType, $packId, $isDefaultPiece, $productId);
		}
		$pieceTintColorCount = $hashedIds ? VarInt::readUnsignedInt($in) : LE::readUnsignedInt($in);
		$pieceTintColors = [];
		for($i = 0; $i < $pieceTintColorCount; ++$i){
			$pieceType = self::getString($in);
			$colors = [];
			if($hashedIds){
				for($j = 0; $j < 4; ++$j){
					$colors[] = self::decodeHexColor(LE::readUnsignedInt($in));
				}
			}else{
				$colorCount = LE::readUnsignedInt($in);
				for($j = 0; $j < $colorCount; ++$j){
					$colors[] = self::getString($in);
				}
			}
			$pieceTintColors[] = new PersonaPieceTintColor(
				$pieceType,
				$colors
			);
		}

		$premium = self::getBool($in);
		$persona = self::getBool($in);
		$capeOnClassic = self::getBool($in);
		$isPrimaryUser = self::getBool($in);
		$override = self::getBool($in);

		if($hashedIds){
			self::getString($in); //trustedSkinFlag - unused
			self::getString($in); //profileHash - unused
		}

		return new SkinData(
			$skinId,
			$skinPlayFabId,
			$skinResourcePatch,
			$skinData,
			$animations,
			$capeData,
			$geometryData,
			$geometryDataVersion,
			$animationData,
			$capeId,
			$fullSkinId,
			$armSize,
			$skinColor,
			$personaPieces,
			$pieceTintColors,
			true,
			$premium,
			$persona,
			$capeOnClassic,
			$isPrimaryUser,
			$override,
		);
	}

	public static function putSkin(ByteBufferWriter $out, SkinData $skin, int $protocolId) : void{
		$hashedIds = $protocolId >= ProtocolInfo::PROTOCOL_1_26_40;

		self::putString($out, $skin->getSkinId());
		self::putString($out, $skin->getPlayFabId());
		self::putString($out, $skin->getResourcePatch());
		self::putSkinImage($out, $skin->getSkinImage());
		if($hashedIds){
			VarInt::writeUnsignedInt($out, count($skin->getAnimations()));
		}else{
			LE::writeUnsignedInt($out, count($skin->getAnimations()));
		}
		foreach($skin->getAnimations() as $animation){
			self::putSkinImage($out, $animation->getImage());
			if($hashedIds){
				VarInt::writeUnsignedInt($out, $animation->getType());
			}else{
				LE::writeUnsignedInt($out, $animation->getType());
			}
			LE::writeFloat($out, $animation->getFrames());
			if($hashedIds){
				VarInt::writeUnsignedInt($out, $animation->getExpressionType());
			}else{
				LE::writeUnsignedInt($out, $animation->getExpressionType());
			}
		}
		self::putSkinImage($out, $skin->getCapeImage());
		self::putString($out, $skin->getGeometryData());
		self::putString($out, $skin->getGeometryDataEngineVersion());
		self::putString($out, $skin->getAnimationData());
		self::putString($out, $skin->getCapeId());
		self::putString($out, $skin->getFullSkinId());
		if($hashedIds){
			Byte::writeUnsigned($out, $skin->getArmSize() === SkinData::ARM_SIZE_SLIM ? 0 : 1);
			LE::writeUnsignedInt($out, self::encodeHexColor($skin->getSkinColor()));
		}else{
			self::putString($out, $skin->getArmSize());
			self::putString($out, $skin->getSkinColor());
		}
		if($hashedIds){
			VarInt::writeUnsignedInt($out, count($skin->getPersonaPieces()));
		}else{
			LE::writeUnsignedInt($out, count($skin->getPersonaPieces()));
		}
		foreach($skin->getPersonaPieces() as $piece){
			self::putString($out, $piece->getPieceId());
			if($hashedIds){
				LE::writeSignedInt($out, self::personaPieceTypeToInt($piece->getPieceType()));
				self::putUUID($out, Uuid::fromString($piece->getPackId() !== "" ? $piece->getPackId() : Uuid::NIL));
			}else{
				self::putString($out, $piece->getPieceType());
				self::putString($out, $piece->getPackId());
			}
			self::putBool($out, $piece->isDefaultPiece());
			self::putString($out, $piece->getProductId());
		}
		if($hashedIds){
			VarInt::writeUnsignedInt($out, count($skin->getPieceTintColors()));
		}else{
			LE::writeUnsignedInt($out, count($skin->getPieceTintColors()));
		}
		foreach($skin->getPieceTintColors() as $tint){
			self::putString($out, $tint->getPieceType());
			if($hashedIds){
				for($j = 0; $j < 4; ++$j){
					LE::writeUnsignedInt($out, self::encodeHexColor($tint->getColors()[$j] ?? ""));
				}
			}else{
				LE::writeUnsignedInt($out, count($tint->getColors()));
				foreach($tint->getColors() as $color){
					self::putString($out, $color);
				}
			}
		}
		self::putBool($out, $skin->isPremium());
		self::putBool($out, $skin->isPersona());
		self::putBool($out, $skin->isPersonaCapeOnClassic());
		self::putBool($out, $skin->isPrimaryUser());
		self::putBool($out, $skin->isOverride());

		if($hashedIds){
			self::putString($out, "true"); //trustedSkinFlag
			self::putString($out, ""); //profileHash
		}
	}

	private const PERSONA_PIECE_TYPES = [
		"persona_skeleton" => 0,
		"persona_body" => 1,
		"persona_skin" => 2,
		"persona_bottom" => 3,
		"persona_feet" => 4,
		"dress" => 5,
		"persona_top" => 6,
		"high_pants" => 7,
		"hands" => 8,
		"outerwear" => 9,
		"persona_facial_hair" => 10,
		"persona_mouth" => 11,
		"persona_eyes" => 12,
		"persona_hair" => 13,
		"hood" => 14,
		"back" => 15,
		"face_accessory" => 16,
		"head" => 17,
		"legs" => 18,
		"left_leg" => 19,
		"right_leg" => 20,
		"arms" => 21,
		"left_arm" => 22,
		"right_arm" => 23,
		"capes" => 24,
		"classic_skin" => 25,
		"emote" => 26,
	];

	private static function personaPieceTypeFromInt(int $type) : string{
		return array_flip(self::PERSONA_PIECE_TYPES)[$type] ?? "persona_skeleton";
	}

	private static function personaPieceTypeToInt(string $type) : int{
		return self::PERSONA_PIECE_TYPES[$type] ?? 0;
	}

	private static function decodeHexColor(int $value) : string{
		return "#" . str_pad(dechex($value & 0xFFFFFFFF), 8, "0", STR_PAD_LEFT);
	}

	private static function encodeHexColor(string $color) : int{
		$hex = ltrim($color, "#");
		return $hex === "" ? 0 : ((int) hexdec($hex)) & 0xFFFFFFFF;
	}

	/** @throws DataDecodeException */
	private static function getSkinImage(ByteBufferReader $in) : SkinImage{
		$width = LE::readUnsignedInt($in);
		$height = LE::readUnsignedInt($in);
		$data = self::getString($in);
		try{
			return new SkinImage($height, $width, $data);
		}catch(\InvalidArgumentException $e){
			throw new PacketDecodeException($e->getMessage(), 0, $e);
		}
	}

	private static function putSkinImage(ByteBufferWriter $out, SkinImage $image) : void{
		LE::writeUnsignedInt($out, $image->getWidth());
		LE::writeUnsignedInt($out, $image->getHeight());
		self::putString($out, $image->getData());
	}

	/**
	 * @return int[]
	 * @phpstan-return array{0: int, 1: int, 2: int}
	 * @throws DataDecodeException
	 */
	private static function getItemStackHeader(ByteBufferReader $in, int $protocolId) : array{
		$id = VarInt::readSignedInt($in);
		if($id === 0 && $protocolId < ProtocolInfo::PROTOCOL_1_26_40){
			return [0, 0, 0];
		}

		$count = LE::readUnsignedShort($in);
		$meta = VarInt::readUnsignedInt($in);

		return [$id, $count, $meta];
	}

	private static function putItemStackHeader(ByteBufferWriter $out, ItemStack $itemStack, int $protocolId) : bool{
		if($itemStack->getId() === 0 && $protocolId < ProtocolInfo::PROTOCOL_1_26_40){
			VarInt::writeSignedInt($out, 0);
			return false;
		}

		VarInt::writeSignedInt($out, $itemStack->getId());
		LE::writeUnsignedShort($out, $itemStack->getCount());
		VarInt::writeUnsignedInt($out, $itemStack->getMeta());

		return true;
	}

	/** @throws DataDecodeException */
	private static function getItemStackFooter(ByteBufferReader $in, int $id, int $meta, int $count) : ItemStack{
		$blockRuntimeId = VarInt::readSignedInt($in);
		$rawExtraData = self::getString($in);

		return new ItemStack($id, $meta, $count, $blockRuntimeId, $rawExtraData);
	}

	private static function putItemStackFooter(ByteBufferWriter $out, ItemStack $itemStack) : void{
		VarInt::writeSignedInt($out, $itemStack->getBlockRuntimeId());
		self::putString($out, $itemStack->getRawExtraData());
	}

	/**
	 * @throws PacketDecodeException
	 * @throws DataDecodeException
	 */
	public static function getItemStackWithoutStackId(ByteBufferReader $in, int $protocolId) : ItemStack{
		[$id, $count, $meta] = self::getItemStackHeader($in, $protocolId);

		return $id !== 0 || $protocolId >= ProtocolInfo::PROTOCOL_1_26_40 ? self::getItemStackFooter($in, $id, $meta, $count) : ItemStack::null();

	}

	public static function putItemStackWithoutStackId(ByteBufferWriter $out, ItemStack $itemStack, int $protocolId) : void{
		if(self::putItemStackHeader($out, $itemStack, $protocolId)){
			self::putItemStackFooter($out, $itemStack);
		}
	}

	/**
	 * Used only by DeprecatedCraftingResultsStackRequestAction (ItemStackRequest CRAFT_RESULTS_DEPRECATED action) on
	 * protocol >= 1.26.40. Unlike getItemStackWithoutStackId(), this identifies the item by its string identifier
	 * (e.g. "minecraft:oak_planks") instead of numeric ID, matching CloudburstMC's
	 * readItemStackRequestNetworkItemInstanceDescriptor().
	 *
	 * @throws DataDecodeException
	 */
	public static function getItemStackRequestNetworkItemInstanceDescriptor(ByteBufferReader $in, int $protocolId) : ItemStack{
		$descriptorType = VarInt::readUnsignedInt($in);
		Byte::readUnsigned($in); //redundant type byte
		if($descriptorType === 0){
			LE::readSignedShort($in); //count, unused for air
			VarInt::readUnsignedInt($in); //blockRuntimeId, unused for air
			self::getString($in); //userData, unused (should be empty)
			return ItemStack::null();
		}

		$stringId = self::getString($in);
		$meta = VarInt::readSignedInt($in);
		$id = TypeConverter::getInstance($protocolId)->getItemTypeDictionary()->fromStringId($stringId);
		$count = LE::readSignedShort($in);
		$blockRuntimeId = VarInt::readUnsignedInt($in);
		$rawExtraData = self::getString($in);

		return new ItemStack($id, $meta, $count, $blockRuntimeId, $rawExtraData);
	}

	public static function putItemStackRequestNetworkItemInstanceDescriptor(ByteBufferWriter $out, ItemStack $itemStack, int $protocolId) : void{
		if($itemStack->getId() === 0){
			VarInt::writeUnsignedInt($out, 0);
			Byte::writeUnsigned($out, 0);
			LE::writeSignedShort($out, 0);
			VarInt::writeUnsignedInt($out, 0);
			self::putString($out, "");
			return;
		}

		VarInt::writeUnsignedInt($out, 1);
		Byte::writeUnsigned($out, 1);
		self::putString($out, TypeConverter::getInstance($protocolId)->getItemTypeDictionary()->fromIntId($itemStack->getId()));
		VarInt::writeSignedInt($out, $itemStack->getMeta());
		LE::writeSignedShort($out, $itemStack->getCount());
		VarInt::writeUnsignedInt($out, $itemStack->getBlockRuntimeId());
		self::putString($out, $itemStack->getRawExtraData());
	}

	/** @throws DataDecodeException */
	public static function getItemStackWrapper(ByteBufferReader $in, int $protocolId) : ItemStackWrapper{
		[$id, $count, $meta] = self::getItemStackHeader($in, $protocolId);
		if($id === 0 && $protocolId < ProtocolInfo::PROTOCOL_1_26_40){
			return new ItemStackWrapper(0, ItemStack::null());
		}

		$hasNetId = self::getBool($in);
		$stackId = $hasNetId ? self::readServerItemStackId($in) : 0;

		$itemStack = self::getItemStackFooter($in, $id, $meta, $count);

		return new ItemStackWrapper($stackId, $itemStack);
	}

	public static function putItemStackWrapper(ByteBufferWriter $out, ItemStackWrapper $itemStackWrapper, int $protocolId) : void{
		$itemStack = $itemStackWrapper->getItemStack();
		if(self::putItemStackHeader($out, $itemStack, $protocolId)){
			$hasNetId = $itemStackWrapper->getStackId() !== 0;
			self::putBool($out, $hasNetId);
			if($hasNetId){
				self::writeServerItemStackId($out, $itemStackWrapper->getStackId());
			}

			self::putItemStackFooter($out, $itemStack);
		}
	}

	public static function getNetworkItemStackDescriptor(ByteBufferReader $in, int $protocolId) : ItemStackWrapper{
		$id = LE::readSignedShort($in);
		$count = LE::readUnsignedShort($in);
		$meta = VarInt::readUnsignedInt($in);

		$hasNetId = self::getBool($in);
		$stackId = 0;
		if($hasNetId){
			//official pmmp/BedrockProtocol@e56263fe5 (Bedrock 1.26.20) introduced this "variant" VarInt
			//right before the stack ID - present for protocol 975 up to (but not including) 2168, which
			//has its own later format that dropped/replaced it again. Confirmed live 2026-08-10: adding
			//this unconditionally for ALL protocols >= 1.26.20 (instead of just the 975-1001 range) broke
			//2168 clients, which don't have this field - so it must be gated to the 975-1001 window only.
			if($protocolId < ProtocolInfo::PROTOCOL_1_26_40){
				VarInt::readUnsignedInt($in); //"variant" - otherwise unused
			}
			$stackId = VarInt::readSignedInt($in);
		}

		//NOT a plain VarInt::readUnsignedInt() - the official upstream diff uses that literally, but
		//our VarInt doesn't sign-extend on its own, so any item with the common "not a block"
		//sentinel (blockRuntimeId = -1, i.e. 0xFFFFFFFF on the wire) would decode as a huge positive
		//number instead. Confirmed 2026-08-10: this silently broke moving ANY non-block item (which
		//is almost everything) on protocol 975/1001/2168 alike - the server would misread what the
		//client claims an item's "before" state is when validating a move, reject the transaction, and
		//the item would just snap back with no visible error. getBlockRuntimeId() already does the
		//correct sign-extension and was used here before this function existed - keep using it.
		$blockRuntimeId = self::getBlockRuntimeId($in);
		$rawExtraData = self::getString($in);

		return new ItemStackWrapper($stackId, new ItemStack($id, $meta, $count, $blockRuntimeId, $rawExtraData));
	}

	public static function putNetworkItemStackDescriptor(ByteBufferWriter $out, ItemStackWrapper $itemStackWrapper, int $protocolId) : void{
		LE::writeSignedShort($out, $itemStackWrapper->getItemStack()->getId());
		LE::writeUnsignedShort($out, $itemStackWrapper->getItemStack()->getCount());
		VarInt::writeUnsignedInt($out, $itemStackWrapper->getItemStack()->getMeta());

		self::putBool($out, $hasNetId = $itemStackWrapper->getStackId() !== 0);
		if($hasNetId){
			//see the comment in getNetworkItemStackDescriptor() - the "variant" field is 975-1001
			//only, NOT 2168+. The stack ID itself, on the other hand, is written zigzag-signed
			//(writeSignedInt, NOT writeUnsignedInt) for every protocol - confirmed 2026-08-10 that
			//our VarInt's signed/unsigned forms are NOT wire-compatible (writeSignedInt zigzags,
			//writeUnsignedInt writes the raw magnitude - completely different bytes for the same
			//int), so blindly copying the official upstream diff's writeUnsignedInt() here broke
			//every inventory move/interaction on 975/1001/2168 alike, even though the "variant"
			//field fix itself was correct. This was already writeSignedInt before this function
			//existed - keep it that way, it was never broken.
			if($protocolId < ProtocolInfo::PROTOCOL_1_26_40){
				VarInt::writeUnsignedInt($out, 0);
			}
			VarInt::writeSignedInt($out, $itemStackWrapper->getStackId());
		}

		self::putBlockRuntimeId($out, $itemStackWrapper->getItemStack()->getBlockRuntimeId());
		self::putString($out, $itemStackWrapper->getItemStack()->getRawExtraData());
	}

	/** @throws DataDecodeException */
	public static function getRecipeIngredient(ByteBufferReader $in, int $protocolId) : RecipeIngredient{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			return self::getRecipeIngredientNamed($in, $protocolId);
		}
		$descriptorType = Byte::readUnsigned($in);
		$descriptor = match($descriptorType){
			ItemDescriptorType::INT_ID_META => IntIdMetaItemDescriptor::read($in),
			ItemDescriptorType::STRING_ID_META => StringIdMetaItemDescriptor::read($in),
			ItemDescriptorType::TAG => TagItemDescriptor::read($in),
			ItemDescriptorType::MOLANG => MolangItemDescriptor::read($in),
			ItemDescriptorType::COMPLEX_ALIAS => ComplexAliasItemDescriptor::read($in),
			default => null
		};
		$count = VarInt::readSignedInt($in);

		return new RecipeIngredient($descriptor, $count);
	}

	/**
	 * Protocol >= 1.26.40 identifies the descriptor type by name (a string) instead of purely by an enum ordinal,
	 * and identifies the "default" (int id + meta) descriptor's item by its string identifier instead of its
	 * numeric ID.
	 *
	 * @throws DataDecodeException
	 */
	private static function getRecipeIngredientNamed(ByteBufferReader $in, int $protocolId) : RecipeIngredient{
		$hasDescriptor = VarInt::readUnsignedInt($in) !== 0;
		if(!$hasDescriptor){
			VarInt::readSignedInt($in); //unused aux value
			$count = VarInt::readSignedInt($in);
			return new RecipeIngredient(null, $count);
		}

		$typeName = self::getString($in);
		$descriptor = match($typeName){
			"name" => new IntIdMetaItemDescriptor(
				TypeConverter::getInstance($protocolId)->getItemTypeDictionary()->fromStringId(self::getString($in)),
				VarInt::readSignedInt($in)
			),
			"molang" => new MolangItemDescriptor(self::getString($in), LE::readSignedShort($in)),
			"item_tag" => self::getRecipeIngredientItemTag($in),
			default => throw new PacketDecodeException("Unsupported item descriptor type name \"$typeName\""),
		};
		$count = VarInt::readSignedInt($in);

		return new RecipeIngredient($descriptor, $count);
	}

	/** @throws DataDecodeException */
	private static function getRecipeIngredientItemTag(ByteBufferReader $in) : TagItemDescriptor{
		$tag = self::getString($in);
		VarInt::readSignedInt($in); //unused aux value
		return new TagItemDescriptor($tag);
	}

	public static function putRecipeIngredient(ByteBufferWriter $out, RecipeIngredient $ingredient, int $protocolId) : void{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			self::putRecipeIngredientNamed($out, $ingredient, $protocolId);
			return;
		}
		$type = $ingredient->getDescriptor();

		Byte::writeUnsigned($out, $type?->getTypeId() ?? 0);
		$type?->write($out);

		VarInt::writeSignedInt($out, $ingredient->getCount());
	}

	private static function putRecipeIngredientNamed(ByteBufferWriter $out, RecipeIngredient $ingredient, int $protocolId) : void{
		$descriptor = $ingredient->getDescriptor();

		VarInt::writeUnsignedInt($out, $descriptor !== null ? 1 : 0);
		if($descriptor === null){
			VarInt::writeSignedInt($out, 0);
			VarInt::writeSignedInt($out, $ingredient->getCount());
			return;
		}

		if($descriptor instanceof IntIdMetaItemDescriptor){
			self::putString($out, "name");
			self::putString($out, TypeConverter::getInstance($protocolId)->getItemTypeDictionary()->fromIntId($descriptor->getId()));
			VarInt::writeSignedInt($out, $descriptor->getMeta());
		}elseif($descriptor instanceof MolangItemDescriptor){
			self::putString($out, "molang");
			self::putString($out, $descriptor->getMolangExpression());
			LE::writeSignedShort($out, $descriptor->getMolangVersion());
		}elseif($descriptor instanceof TagItemDescriptor){
			self::putString($out, "item_tag");
			self::putString($out, $descriptor->getTag());
			VarInt::writeSignedInt($out, 0); //unused aux value
		}else{
			throw new \InvalidArgumentException("Unsupported item descriptor type " . get_class($descriptor) . " for protocol >= 1.26.40");
		}

		VarInt::writeSignedInt($out, $ingredient->getCount());
	}

	/**
	 * Decodes entity metadata from the stream.
	 *
	 * @return MetadataProperty[]
	 * @phpstan-return array<int, MetadataProperty>
	 *
	 * @throws PacketDecodeException
	 * @throws DataDecodeException
	 */
	public static function getEntityMetadata(ByteBufferReader $in, int $protocolId) : array{
		$count = VarInt::readUnsignedInt($in);
		$data = [];
		for($i = 0; $i < $count; ++$i){
			$key = VarInt::readUnsignedInt($in);
			$type = VarInt::readUnsignedInt($in);
			if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
				Byte::readUnsigned($in); //redundant type byte
			}

			$data[$key] = self::readMetadataProperty($in, $type);
		}

		return $data;
	}

	/** @throws DataDecodeException */
	private static function readMetadataProperty(ByteBufferReader $in, int $type) : MetadataProperty{
		return match($type){
			ByteMetadataProperty::ID => ByteMetadataProperty::read($in),
			ShortMetadataProperty::ID => ShortMetadataProperty::read($in),
			IntMetadataProperty::ID => IntMetadataProperty::read($in),
			FloatMetadataProperty::ID => FloatMetadataProperty::read($in),
			StringMetadataProperty::ID => StringMetadataProperty::read($in),
			CompoundTagMetadataProperty::ID => CompoundTagMetadataProperty::read($in),
			BlockPosMetadataProperty::ID => BlockPosMetadataProperty::read($in),
			LongMetadataProperty::ID => LongMetadataProperty::read($in),
			Vec3MetadataProperty::ID => Vec3MetadataProperty::read($in),
			default => throw new PacketDecodeException("Unknown entity metadata type " . $type),
		};
	}

	/**
	 * Writes entity metadata to the packet buffer.
	 *
	 * @param MetadataProperty[] $metadata
	 *
	 * @phpstan-param array<int, MetadataProperty> $metadata
	 */
	public static function putEntityMetadata(ByteBufferWriter $out, array $metadata, int $protocolId) : void{
		VarInt::writeUnsignedInt($out, count($metadata));
		foreach($metadata as $key => $d){
			VarInt::writeUnsignedInt($out, $key);
			VarInt::writeUnsignedInt($out, $d->getTypeId());
			if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
				Byte::writeUnsigned($out, $d->getTypeId());
			}
			$d->write($out);
		}
	}

	/** @throws DataDecodeException */
	public static function getActorUniqueId(ByteBufferReader $in) : int{
		return VarInt::readSignedLong($in);
	}

	public static function putActorUniqueId(ByteBufferWriter $out, int $eid) : void{
		VarInt::writeSignedLong($out, $eid);
	}

	/** @throws DataDecodeException */
	public static function getActorRuntimeId(ByteBufferReader $in) : int{
		return VarInt::readUnsignedLong($in);
	}

	public static function putActorRuntimeId(ByteBufferWriter $out, int $eid) : void{
		VarInt::writeUnsignedLong($out, $eid);
	}

	/**
	 * Reads a block position
	 *
	 * @throws DataDecodeException
	 */
	public static function getBlockPosition(ByteBufferReader $in, bool $signedY = true) : BlockPosition{
		$x = VarInt::readSignedInt($in);
		$y = $signedY ? VarInt::readSignedInt($in) : Binary::signInt(VarInt::readUnsignedInt($in));
		$z = VarInt::readSignedInt($in);
		return new BlockPosition($x, $y, $z);
	}

	/**
	 * Writes a block position
	 */
	public static function putBlockPosition(ByteBufferWriter $out, BlockPosition $blockPosition, bool $signedY = true) : void{
		VarInt::writeSignedInt($out, $blockPosition->getX());
		if($signedY){
			VarInt::writeSignedInt($out, $blockPosition->getY());
		}else{
			VarInt::writeUnsignedInt($out, Binary::unsignInt($blockPosition->getY()));
		}
		VarInt::writeSignedInt($out, $blockPosition->getZ());
	}

	/**
	 * Reads a floating-point Vector3 object with coordinates rounded to 4 decimal places.
	 *
	 * @throws DataDecodeException
	 */
	public static function getVector3(ByteBufferReader $in) : Vector3{
		$x = LE::readFloat($in);
		$y = LE::readFloat($in);
		$z = LE::readFloat($in);
		return new Vector3($x, $y, $z);
	}

	/**
	 * Reads a floating-point Vector2 object with coordinates rounded to 4 decimal places.
	 *
	 * @throws DataDecodeException
	 */
	public static function getVector2(ByteBufferReader $in) : Vector2{
		$x = LE::readFloat($in);
		$y = LE::readFloat($in);
		return new Vector2($x, $y);
	}

	/**
	 * Writes a floating-point Vector3 object, or 3x zero if null is given.
	 *
	 * Note: ONLY use this where it is reasonable to allow not specifying the vector.
	 * For all other purposes, use the non-nullable version.
	 *
	 * @see CommonTypes::putVector3()
	 */
	public static function putVector3Nullable(ByteBufferWriter $out, ?Vector3 $vector) : void{
		if($vector !== null){
			self::putVector3($out, $vector);
		}else{
			LE::writeFloat($out, 0.0);
			LE::writeFloat($out, 0.0);
			LE::writeFloat($out, 0.0);
		}
	}

	/**
	 * Writes a floating-point Vector3 object
	 */
	public static function putVector3(ByteBufferWriter $out, Vector3 $vector) : void{
		LE::writeFloat($out, $vector->x);
		LE::writeFloat($out, $vector->y);
		LE::writeFloat($out, $vector->z);
	}

	/**
	 * Writes a floating-point Vector2 object
	 */
	public static function putVector2(ByteBufferWriter $out, Vector2 $vector2) : void{
		LE::writeFloat($out, $vector2->x);
		LE::writeFloat($out, $vector2->y);
	}

	/** @throws DataDecodeException */
	public static function getRotationByte(ByteBufferReader $in) : float{
		return Byte::readUnsigned($in) * (360 / 256);
	}

	public static function putRotationByte(ByteBufferWriter $out, float $rotation) : void{
		Byte::writeUnsigned($out, (int) ($rotation / (360 / 256)));
	}

	/** @throws DataDecodeException */
	private static function readGameRule(ByteBufferReader $in, int $protocolId, int $type, bool $isPlayerModifiable, bool $isStartGame) : GameRule{
		return match($type){
			BoolGameRule::ID => BoolGameRule::decode($in, $protocolId, $isPlayerModifiable),
			IntGameRule::ID => IntGameRule::decode($in, $protocolId, $isPlayerModifiable, $isStartGame),
			FloatGameRule::ID => FloatGameRule::decode($in, $protocolId, $isPlayerModifiable),
			default => throw new PacketDecodeException("Unknown gamerule type $type"),
		};
	}

	/**
	 * Reads gamerules
	 *
	 * @return GameRule[] game rule name => value
	 * @phpstan-return array<string, GameRule>
	 *
	 * @throws PacketDecodeException
	 * @throws DataDecodeException
	 */
	public static function getGameRules(ByteBufferReader $in, int $protocolId, bool $isStartGame) : array{
		$count = VarInt::readUnsignedInt($in);
		$rules = [];
		for($i = 0; $i < $count; ++$i){
			$name = self::getString($in);
			$isPlayerModifiable = self::getBool($in);
			$type = VarInt::readUnsignedInt($in);
			$rules[$name] = self::readGameRule($in, $protocolId, $type, $isPlayerModifiable, $isStartGame);
		}

		return $rules;
	}

	/**
	 * Writes a gamerule array
	 *
	 * @param GameRule[] $rules
	 * @phpstan-param array<string, GameRule> $rules
	 */
	public static function putGameRules(ByteBufferWriter $out, int $protocolId, array $rules, bool $isStartGame) : void{
		VarInt::writeUnsignedInt($out, count($rules));
		foreach($rules as $name => $rule){
			self::putString($out, $name);
			self::putBool($out, $rule->isPlayerModifiable());
			VarInt::writeUnsignedInt($out, $rule->getTypeId());
			$rule->encode($out, $protocolId, $isStartGame);
		}
	}

	/** @throws DataDecodeException */
	public static function getEntityLink(ByteBufferReader $in, int $protocolId) : EntityLink{
		$fromActorUniqueId = self::getActorUniqueId($in);
		$toActorUniqueId = self::getActorUniqueId($in);
		$type = Byte::readUnsigned($in);
		$immediate = self::getBool($in);
		$causedByRider = self::getBool($in);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_21_20){
			$vehicleAngularVelocity = LE::readFloat($in);
		}
		return new EntityLink($fromActorUniqueId, $toActorUniqueId, $type, $immediate, $causedByRider, $vehicleAngularVelocity ?? 0);
	}

	public static function putEntityLink(ByteBufferWriter $out, int $protocolId, EntityLink $link) : void{
		self::putActorUniqueId($out, $link->fromActorUniqueId);
		self::putActorUniqueId($out, $link->toActorUniqueId);
		Byte::writeUnsigned($out, $link->type);
		self::putBool($out, $link->immediate);
		self::putBool($out, $link->causedByRider);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_21_20){
			LE::writeFloat($out, $link->vehicleAngularVelocity);
		}
	}

	/** @throws DataDecodeException */
	public static function getCommandOriginData(ByteBufferReader $in, int $protocolId) : CommandOriginData{
		$result = new CommandOriginData();

		$result->type = $protocolId >= ProtocolInfo::PROTOCOL_1_21_130 ? CommonTypes::getString($in) : CommandOriginData::getTypeFromId(VarInt::readUnsignedInt($in));
		$result->uuid = self::getUUID($in);
		$result->requestId = self::getString($in);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_21_130){
			$result->playerActorUniqueId = LE::readSignedLong($in);
		}elseif($result->type === CommandOriginData::ORIGIN_DEV_CONSOLE or $result->type === CommandOriginData::ORIGIN_TEST){
			$result->playerActorUniqueId = VarInt::readSignedLong($in);
		}

		return $result;
	}

	public static function putCommandOriginData(ByteBufferWriter $out, CommandOriginData $data, int $protocolId) : void{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_21_130){
			self::putString($out, $data->type);
		}else{
			VarInt::writeUnsignedInt($out, CommandOriginData::getIdFromType($data->type));
		}
		self::putUUID($out, $data->uuid);
		self::putString($out, $data->requestId);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_21_130){
			LE::writeSignedLong($out, $data->playerActorUniqueId);
		}elseif($data->type === CommandOriginData::ORIGIN_DEV_CONSOLE or $data->type === CommandOriginData::ORIGIN_TEST){
			VarInt::writeSignedLong($out, $data->playerActorUniqueId);
		}
	}

	/** @throws DataDecodeException */
	public static function getStructureSettings(ByteBufferReader $in, int $protocolId) : StructureSettings{
		$result = new StructureSettings();

		$result->paletteName = self::getString($in);

		$result->ignoreEntities = self::getBool($in);
		$result->ignoreBlocks = self::getBool($in);
		$result->allowNonTickingChunks = self::getBool($in);

		$result->dimensions = self::getBlockPosition($in, $protocolId >= ProtocolInfo::PROTOCOL_1_26_10);
		$result->offset = self::getBlockPosition($in, $protocolId >= ProtocolInfo::PROTOCOL_1_26_10);

		$result->lastTouchedByPlayerID = self::getActorUniqueId($in);
		$result->rotation = Byte::readUnsigned($in);
		$result->mirror = Byte::readUnsigned($in);
		$result->animationMode = Byte::readUnsigned($in);
		$result->animationSeconds = LE::readFloat($in);
		$result->integrityValue = LE::readFloat($in);
		$result->integritySeed = LE::readUnsignedInt($in);
		$result->pivot = self::getVector3($in);

		return $result;
	}

	public static function putStructureSettings(ByteBufferWriter $out, StructureSettings $structureSettings, int $protocolId) : void{
		self::putString($out, $structureSettings->paletteName);

		self::putBool($out, $structureSettings->ignoreEntities);
		self::putBool($out, $structureSettings->ignoreBlocks);
		self::putBool($out, $structureSettings->allowNonTickingChunks);

		self::putBlockPosition($out, $structureSettings->dimensions, $protocolId >= ProtocolInfo::PROTOCOL_1_26_10);
		self::putBlockPosition($out, $structureSettings->offset, $protocolId >= ProtocolInfo::PROTOCOL_1_26_10);

		self::putActorUniqueId($out, $structureSettings->lastTouchedByPlayerID);
		Byte::writeUnsigned($out, $structureSettings->rotation);
		Byte::writeUnsigned($out, $structureSettings->mirror);
		Byte::writeUnsigned($out, $structureSettings->animationMode);
		LE::writeFloat($out, $structureSettings->animationSeconds);
		LE::writeFloat($out, $structureSettings->integrityValue);
		LE::writeUnsignedInt($out, $structureSettings->integritySeed);
		self::putVector3($out, $structureSettings->pivot);
	}

	/** @throws DataDecodeException */
	public static function getStructureEditorData(ByteBufferReader $in, int $protocolId) : StructureEditorData{
		$result = new StructureEditorData();

		$result->structureName = self::getString($in);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_21_60){
			$result->filteredStructureName = self::getString($in);
		}
		$result->structureDataField = self::getString($in);

		$result->includePlayers = self::getBool($in);
		$result->showBoundingBox = self::getBool($in);

		$result->structureBlockType = VarInt::readSignedInt($in);
		$result->structureSettings = self::getStructureSettings($in, $protocolId);
		$result->structureRedstoneSaveMode = VarInt::readSignedInt($in);

		return $result;
	}

	public static function putStructureEditorData(ByteBufferWriter $out, int $protocolId, StructureEditorData $structureEditorData) : void{
		self::putString($out, $structureEditorData->structureName);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_21_60){
			self::putString($out, $structureEditorData->filteredStructureName);
		}
		self::putString($out, $structureEditorData->structureDataField);

		self::putBool($out, $structureEditorData->includePlayers);
		self::putBool($out, $structureEditorData->showBoundingBox);

		VarInt::writeSignedInt($out, $structureEditorData->structureBlockType);
		self::putStructureSettings($out, $structureEditorData->structureSettings, $protocolId);
		VarInt::writeSignedInt($out, $structureEditorData->structureRedstoneSaveMode);
	}

	/** @throws PacketDecodeException */
	public static function getNbtRoot(ByteBufferReader $in) : TreeRoot{
		$offset = $in->getOffset();
		try{
			return (new NetworkNbtSerializer())->read($in->getData(), $offset, 512);
		}catch(NbtDataException $e){
			throw PacketDecodeException::wrap($e, "Failed decoding NBT root");
		}finally{
			$in->setOffset($offset);
		}
	}

	public static function getNbtCompoundRoot(ByteBufferReader $in) : CompoundTag{
		try{
			return self::getNbtRoot($in)->mustGetCompoundTag();
		}catch(NbtDataException $e){
			throw PacketDecodeException::wrap($e, "Expected TAG_Compound NBT root");
		}
	}

	/** @throws DataDecodeException */
	public static function readRecipeNetId(ByteBufferReader $in) : int{
		return VarInt::readUnsignedInt($in);
	}

	public static function writeRecipeNetId(ByteBufferWriter $out, int $id) : void{
		VarInt::writeUnsignedInt($out, $id);
	}

	/** @throws DataDecodeException */
	public static function readCreativeItemNetId(ByteBufferReader $in) : int{
		return VarInt::readUnsignedInt($in);
	}

	public static function writeCreativeItemNetId(ByteBufferWriter $out, int $id) : void{
		VarInt::writeUnsignedInt($out, $id);
	}

	/**
	 * This is a union of ItemStackRequestId, LegacyItemStackRequestId, and ServerItemStackId, used in serverbound
	 * packets to allow the client to refer to server known items, or items which may have been modified by a previous
	 * as-yet unacknowledged request from the client.
	 *
	 * - Server itemstack ID is positive
	 * - InventoryTransaction "legacy" request ID is negative and even
	 * - ItemStackRequest request ID is negative and odd
	 * - 0 refers to an empty itemstack (air)
	 *
	 * @throws DataDecodeException
	 */
	public static function readItemStackNetIdVariant(ByteBufferReader $in, int $protocolId) : int{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			return LE::readSignedInt($in);
		}
		return VarInt::readSignedInt($in);
	}

	/**
	 * This is a union of ItemStackRequestId, LegacyItemStackRequestId, and ServerItemStackId, used in serverbound
	 * packets to allow the client to refer to server known items, or items which may have been modified by a previous
	 * as-yet unacknowledged request from the client.
	 */
	public static function writeItemStackNetIdVariant(ByteBufferWriter $out, int $id, int $protocolId) : void{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			LE::writeSignedInt($out, $id);
			return;
		}
		VarInt::writeSignedInt($out, $id);
	}

	/** @throws DataDecodeException */
	public static function readItemStackRequestId(ByteBufferReader $in) : int{
		return VarInt::readSignedInt($in);
	}

	public static function writeItemStackRequestId(ByteBufferWriter $out, int $id) : void{
		VarInt::writeSignedInt($out, $id);
	}

	/** @throws DataDecodeException */
	public static function readLegacyItemStackRequestId(ByteBufferReader $in) : int{
		return VarInt::readSignedInt($in);
	}

	public static function writeLegacyItemStackRequestId(ByteBufferWriter $out, int $id) : void{
		VarInt::writeSignedInt($out, $id);
	}

	/** @throws DataDecodeException */
	public static function readServerItemStackId(ByteBufferReader $in) : int{
		return VarInt::readSignedInt($in);
	}

	public static function writeServerItemStackId(ByteBufferWriter $out, int $id) : void{
		VarInt::writeSignedInt($out, $id);
	}

	/**
	 * @phpstan-template T
	 * @phpstan-param \Closure(ByteBufferReader) : (T|null) $reader
	 * @phpstan-return T|null
	 * @throws DataDecodeException
	 */
	public static function readOptional(ByteBufferReader $in, \Closure $reader) : mixed{
		if(self::getBool($in)){
			return $reader($in);
		}
		return null;
	}

	/**
	 * @phpstan-template T
	 * @phpstan-param T|null $value
	 * @phpstan-param \Closure(ByteBufferWriter, T) : void $writer
	 */
	public static function writeOptional(ByteBufferWriter $out, mixed $value, \Closure $writer) : void{
		if($value !== null){
			self::putBool($out, true);
			$writer($out, $value);
		}else{
			self::putBool($out, false);
		}
	}
}
