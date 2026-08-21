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

namespace pocketmine\network\mcpe\protocol\types\login\clientdata;

use pocketmine\network\mcpe\protocol\types\skin\PersonaPieceTintColor;
use pocketmine\network\mcpe\protocol\types\skin\PersonaSkinPiece;
use pocketmine\network\mcpe\protocol\types\skin\SkinAnimation;
use pocketmine\network\mcpe\protocol\types\skin\SkinData;
use pocketmine\network\mcpe\protocol\types\skin\SkinImage;
use function array_map;
use function base64_decode;

final class ClientDataToSkinDataHelper{

	/**
	 * @throws \InvalidArgumentException
	 */
	private static function safeB64Decode(string $base64, string $context) : string{
		$result = base64_decode($base64, true);
		if($result === false){
			throw new \InvalidArgumentException("$context: Malformed base64, cannot be decoded");
		}
		return $result;
	}

	/**
	 * Some clients (observed on 1.26.33) send the literal 4-byte string "null" for SkinGeometryData
	 * instead of an empty string when the skin has no custom geometry - passing that through as-is
	 * produces a PlayerListPacket entry with geometryData='null', which is neither empty nor valid
	 * geometry JSON and crashes the client that receives it (including the client's own entry, shown
	 * back to itself in its own player list).
	 */
	private static function sanitizeGeometryData(string $geometryData) : string{
		//observed on 1.26.30/31/32/33 (branch r/26_u3): the literal string isn't always
		//exactly "null" - some clients append a trailing newline ("null\n"), which the
		//original exact-match check silently failed to catch, letting the broken value
		//through uncaught
		return trim($geometryData) === "null" ? "" : $geometryData;
	}

	/**
	 * Some clients (observed on 1.26.33) send a placeholder "0.0.0" instead of a real Minecraft version
	 * string for SkinGeometryDataEngineVersion - passing that through as-is produces a PlayerListPacket
	 * entry with an engine version that doesn't correspond to any real client build, which crashes the
	 * client that receives it. Falls back to the same version PMMP itself already uses as the default
	 * for server-generated SkinData (ProtocolInfo::MINECRAFT_VERSION_NETWORK), already proven safe.
	 */
	private static function sanitizeEngineVersion(string $engineVersion) : string{
		return ($engineVersion === "" || $engineVersion === "0.0.0" || $engineVersion === "null")
			? \pocketmine\network\mcpe\protocol\ProtocolInfo::MINECRAFT_VERSION_NETWORK
			: $engineVersion;
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	public static function fromClientData(ClientData $clientData) : SkinData{
		/** @var SkinAnimation[] $animations */
		$animations = [];
		foreach($clientData->AnimatedImageData as $k => $animation){
			$animations[] = new SkinAnimation(
				new SkinImage(
					$animation->ImageHeight,
					$animation->ImageWidth,
					self::safeB64Decode($animation->Image, "AnimatedImageData.$k.Image")
				),
				$animation->Type,
				$animation->Frames,
				$animation->AnimationExpression
			);
		}
		return new SkinData(
			$clientData->SkinId,
			$clientData->PlayFabId ?? "",
			self::safeB64Decode($clientData->SkinResourcePatch, "SkinResourcePatch"),
			new SkinImage($clientData->SkinImageHeight, $clientData->SkinImageWidth, self::safeB64Decode($clientData->SkinData, "SkinData")),
			$animations,
			new SkinImage($clientData->CapeImageHeight, $clientData->CapeImageWidth, self::safeB64Decode($clientData->CapeData, "CapeData")),
			self::sanitizeGeometryData(self::safeB64Decode($clientData->SkinGeometryData, "SkinGeometryData")),
			self::sanitizeEngineVersion(self::safeB64Decode($clientData->SkinGeometryDataEngineVersion, "SkinGeometryDataEngineVersion")), //yes, they actually base64'd the version!
			self::safeB64Decode($clientData->SkinAnimationData, "SkinAnimationData"),
			$clientData->CapeId,
			null,
			$clientData->ArmSize,
			$clientData->SkinColor,
			array_map(function(ClientDataPersonaSkinPiece $piece) : PersonaSkinPiece{
				return new PersonaSkinPiece($piece->PieceId, $piece->PieceType, $piece->PackId, $piece->IsDefault, $piece->ProductId);
			}, $clientData->PersonaPieces),
			array_map(function(ClientDataPersonaPieceTintColor $tint) : PersonaPieceTintColor{
				return new PersonaPieceTintColor($tint->PieceType, $tint->Colors);
			}, $clientData->PieceTintColors),
			true,
			$clientData->PremiumSkin,
			$clientData->PersonaSkin,
			$clientData->CapeOnClassicSkin,
			true, //assume this is true? there's no field for it ...
			$clientData->OverrideSkin ?? true,
		);
	}
}
