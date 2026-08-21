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

use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\LE;
use pmmp\encoding\VarInt;
use pocketmine\math\Vector2;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\serializer\BitSet;
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;
use pocketmine\network\mcpe\protocol\types\InputMode;
use pocketmine\network\mcpe\protocol\types\InteractionMode;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\ItemStackRequest;
use pocketmine\network\mcpe\protocol\types\ItemInteractionData;
use pocketmine\network\mcpe\protocol\types\PlayerAction;
use pocketmine\network\mcpe\protocol\types\PlayerAuthInputFlags;
use pocketmine\network\mcpe\protocol\types\PlayerAuthInputVehicleInfo;
use pocketmine\network\mcpe\protocol\types\PlayerBlockAction;
use pocketmine\network\mcpe\protocol\types\PlayerBlockActionStopBreak;
use pocketmine\network\mcpe\protocol\types\PlayerBlockActionWithBlockInfo;
use pocketmine\network\mcpe\protocol\types\PlayMode;
use function assert;
use function count;

class PlayerAuthInputPacket extends DataPacket implements ServerboundPacket{
	public const NETWORK_ID = ProtocolInfo::PLAYER_AUTH_INPUT_PACKET;

	public Vector3 $position;
	private float $pitch;
	private float $yaw;
	private float $headYaw;
	private float $moveVecX;
	private float $moveVecZ;
	private BitSet $inputFlags;
	private int $inputMode;
	private int $playMode;
	private int $interactionMode;
	private ?Vector3 $vrGazeDirection = null;
	private Vector2 $interactRotation;
	private int $tick;
	private Vector3 $delta;
	private ?ItemInteractionData $itemInteractionData = null;
	private ?ItemStackRequest $itemStackRequest = null;
	/** @var PlayerBlockAction[]|null */
	private ?array $blockActions = null;
	private ?PlayerAuthInputVehicleInfo $vehicleInfo = null;
	private float $analogMoveVecX;
	private float $analogMoveVecZ;
	private Vector3 $cameraOrientation;
	private Vector2 $rawMove;

	/**
	 * @generate-create-func
	 * @param PlayerBlockAction[] $blockActions
	 */
	private static function internalCreate(
		Vector3 $position,
		float $pitch,
		float $yaw,
		float $headYaw,
		float $moveVecX,
		float $moveVecZ,
		BitSet $inputFlags,
		int $inputMode,
		int $playMode,
		int $interactionMode,
		?Vector3 $vrGazeDirection,
		Vector2 $interactRotation,
		int $tick,
		Vector3 $delta,
		?ItemInteractionData $itemInteractionData,
		?ItemStackRequest $itemStackRequest,
		?array $blockActions,
		?PlayerAuthInputVehicleInfo $vehicleInfo,
		float $analogMoveVecX,
		float $analogMoveVecZ,
		Vector3 $cameraOrientation,
		Vector2 $rawMove,
	) : self{
		$result = new self;
		$result->position = $position;
		$result->pitch = $pitch;
		$result->yaw = $yaw;
		$result->headYaw = $headYaw;
		$result->moveVecX = $moveVecX;
		$result->moveVecZ = $moveVecZ;
		$result->inputFlags = $inputFlags;
		$result->inputMode = $inputMode;
		$result->playMode = $playMode;
		$result->interactionMode = $interactionMode;
		$result->vrGazeDirection = $vrGazeDirection;
		$result->interactRotation = $interactRotation;
		$result->tick = $tick;
		$result->delta = $delta;
		$result->itemInteractionData = $itemInteractionData;
		$result->itemStackRequest = $itemStackRequest;
		$result->blockActions = $blockActions;
		$result->vehicleInfo = $vehicleInfo;
		$result->analogMoveVecX = $analogMoveVecX;
		$result->analogMoveVecZ = $analogMoveVecZ;
		$result->cameraOrientation = $cameraOrientation;
		$result->rawMove = $rawMove;
		return $result;
	}

	/**
	 * @param BitSet                   $inputFlags @see PlayerAuthInputFlags
	 * @param int                      $inputMode @see InputMode
	 * @param int                      $playMode @see PlayMode
	 * @param int                      $interactionMode @see InteractionMode
	 * @param PlayerBlockAction[]|null $blockActions Blocks that the client has interacted with
	 */
	public static function create(
		Vector3 $position,
		float $pitch,
		float $yaw,
		float $headYaw,
		float $moveVecX,
		float $moveVecZ,
		BitSet $inputFlags,
		int $inputMode,
		int $playMode,
		int $interactionMode,
		?Vector3 $vrGazeDirection,
		Vector2 $interactRotation,
		int $tick,
		Vector3 $delta,
		?ItemInteractionData $itemInteractionData,
		?ItemStackRequest $itemStackRequest,
		?array $blockActions,
		?PlayerAuthInputVehicleInfo $vehicleInfo,
		float $analogMoveVecX,
		float $analogMoveVecZ,
		Vector3 $cameraOrientation,
		Vector2 $rawMove
	) : self{
		if($inputFlags->getLength() !== PlayerAuthInputFlags::NUMBER_OF_FLAGS){
			throw new \InvalidArgumentException("Input flags must be " . PlayerAuthInputFlags::NUMBER_OF_FLAGS . " bits long");
		}

		if($playMode === PlayMode::VR and $vrGazeDirection === null){
			//yuck, can we get a properly written packet just once? ...
			throw new \InvalidArgumentException("Gaze direction must be provided for VR play mode");
		}

		$inputFlags->set(PlayerAuthInputFlags::PERFORM_ITEM_STACK_REQUEST, $itemStackRequest !== null);
		$inputFlags->set(PlayerAuthInputFlags::PERFORM_ITEM_INTERACTION, $itemInteractionData !== null);
		$inputFlags->set(PlayerAuthInputFlags::PERFORM_BLOCK_ACTIONS, $blockActions !== null);
		$inputFlags->set(PlayerAuthInputFlags::IN_CLIENT_PREDICTED_VEHICLE, $vehicleInfo !== null);

		return self::internalCreate(
			$position,
			$pitch,
			$yaw,
			$headYaw,
			$moveVecX,
			$moveVecZ,
			$inputFlags,
			$inputMode,
			$playMode,
			$interactionMode,
			$vrGazeDirection?->asVector3(),
			$interactRotation,
			$tick,
			$delta,
			$itemInteractionData,
			$itemStackRequest,
			$blockActions,
			$vehicleInfo,
			$analogMoveVecX,
			$analogMoveVecZ,
			$cameraOrientation,
			$rawMove
		);
	}

	public function getPosition() : Vector3{
		return $this->position;
	}

	public function getPitch() : float{
		return $this->pitch;
	}

	public function getYaw() : float{
		return $this->yaw;
	}

	public function getHeadYaw() : float{
		return $this->headYaw;
	}

	public function getMoveVecX() : float{
		return $this->moveVecX;
	}

	public function getMoveVecZ() : float{
		return $this->moveVecZ;
	}

	/**
	 * @see PlayerAuthInputFlags
	 */
	public function getInputFlags() : BitSet{
		return $this->inputFlags;
	}

	/**
	 * @see InputMode
	 */
	public function getInputMode() : int{
		return $this->inputMode;
	}

	/**
	 * @see PlayMode
	 */
	public function getPlayMode() : int{
		return $this->playMode;
	}

	/**
	 * @see InteractionMode
	 */
	public function getInteractionMode() : int{
		return $this->interactionMode;
	}

	public function getVrGazeDirection() : ?Vector3{
		return $this->vrGazeDirection;
	}

	public function getInteractRotation() : Vector2{ return $this->interactRotation; }

	public function getTick() : int{
		return $this->tick;
	}

	public function getDelta() : Vector3{
		return $this->delta;
	}

	public function getItemInteractionData() : ?ItemInteractionData{
		return $this->itemInteractionData;
	}

	public function getItemStackRequest() : ?ItemStackRequest{
		return $this->itemStackRequest;
	}

	/**
	 * @return PlayerBlockAction[]|null
	 */
	public function getBlockActions() : ?array{
		return $this->blockActions;
	}

	public function getVehicleInfo() : ?PlayerAuthInputVehicleInfo{ return $this->vehicleInfo; }

	public function getAnalogMoveVecX() : float{ return $this->analogMoveVecX; }

	public function getAnalogMoveVecZ() : float{ return $this->analogMoveVecZ; }

	public function getCameraOrientation() : Vector3{ return $this->cameraOrientation; }

	public function getRawMove() : Vector2{ return $this->rawMove; }

	protected function decodePayload(ByteBufferReader $in, int $protocolId) : void{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			$this->decodePayload2168($in);
			return;
		}
		$this->pitch = LE::readFloat($in);
		$this->yaw = LE::readFloat($in);
		$this->position = CommonTypes::getVector3($in);
		$this->moveVecX = LE::readFloat($in);
		$this->moveVecZ = LE::readFloat($in);
		$this->headYaw = LE::readFloat($in);
		$this->inputFlags = BitSet::read($in, $protocolId >= ProtocolInfo::PROTOCOL_1_21_50 ? PlayerAuthInputFlags::NUMBER_OF_FLAGS : 64);
		$this->inputMode = VarInt::readUnsignedInt($in);
		$this->playMode = VarInt::readUnsignedInt($in);
		$this->interactionMode = VarInt::readUnsignedInt($in);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_21_40){
			$this->interactRotation = CommonTypes::getVector2($in);
		}elseif($this->playMode === PlayMode::VR){
			$this->vrGazeDirection = CommonTypes::getVector3($in);
		}
		$this->tick = VarInt::readUnsignedLong($in);
		$this->delta = CommonTypes::getVector3($in);
		if($this->inputFlags->get(PlayerAuthInputFlags::PERFORM_ITEM_INTERACTION)){
			$this->itemInteractionData = ItemInteractionData::read($in);
		}
		if($this->inputFlags->get(PlayerAuthInputFlags::PERFORM_ITEM_STACK_REQUEST)){
			$this->itemStackRequest = ItemStackRequest::read($in, $protocolId);
		}
		if($this->inputFlags->get(PlayerAuthInputFlags::PERFORM_BLOCK_ACTIONS)){
			$this->blockActions = [];
			$max = VarInt::readSignedInt($in);
			for($i = 0; $i < $max; ++$i){
				$actionType = VarInt::readSignedInt($in);
				$this->blockActions[] = match(true){
					PlayerBlockActionWithBlockInfo::isValidActionType($actionType) => PlayerBlockActionWithBlockInfo::read($in, $actionType),
					$actionType === PlayerAction::STOP_BREAK => new PlayerBlockActionStopBreak(),
					default => throw new PacketDecodeException("Unexpected block action type $actionType")
				};
			}
		}
		if($this->inputFlags->get(PlayerAuthInputFlags::IN_CLIENT_PREDICTED_VEHICLE) && $protocolId >= ProtocolInfo::PROTOCOL_1_20_60){
			$this->vehicleInfo = PlayerAuthInputVehicleInfo::read($in, $protocolId);
		}
		$this->analogMoveVecX = LE::readFloat($in);
		$this->analogMoveVecZ = LE::readFloat($in);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_21_40){
			$this->cameraOrientation = CommonTypes::getVector3($in);
			if($protocolId >= ProtocolInfo::PROTOCOL_1_21_50){
				$this->rawMove = CommonTypes::getVector2($in);
			}
		}
	}

	/**
	 * Protocol >= 1.26.40 replaces the dense input-flags bitset with a bool-gated sparse list of set flag
	 * indices, uses a signed varint for interactionMode, uses an unsigned varint count for block actions
	 * (instead of signed), and wraps each optional trailing section (item interaction, item stack request,
	 * block actions, vehicle rotation, predicted vehicle) in an extra leading bool (which is always true in
	 * practice) in addition to the "is this section present" bool.
	 */
	private function decodePayload2168(ByteBufferReader $in) : void{
		$this->pitch = LE::readFloat($in);
		$this->yaw = LE::readFloat($in);
		$this->position = CommonTypes::getVector3($in);
		$this->moveVecX = LE::readFloat($in);
		$this->moveVecZ = LE::readFloat($in);
		$this->headYaw = LE::readFloat($in);

		$this->inputFlags = new BitSet(PlayerAuthInputFlags::NUMBER_OF_FLAGS_1_26_40);
		if(CommonTypes::getBool($in)){
			for($i = 0, $count = VarInt::readUnsignedInt($in); $i < $count; ++$i){
				$this->inputFlags->set(VarInt::readSignedInt($in), true);
			}
		}

		$this->inputMode = VarInt::readUnsignedInt($in);
		$this->playMode = VarInt::readUnsignedInt($in);
		$this->interactionMode = VarInt::readSignedInt($in);
		$this->interactRotation = CommonTypes::getVector2($in);
		$this->tick = VarInt::readUnsignedLong($in);
		$this->delta = CommonTypes::getVector3($in);

		if(CommonTypes::getBool($in) && CommonTypes::getBool($in)){
			$this->itemInteractionData = ItemInteractionData::read($in);
		}
		if(CommonTypes::getBool($in) && CommonTypes::getBool($in)){
			$this->itemStackRequest = ItemStackRequest::read($in, ProtocolInfo::PROTOCOL_1_26_40);
		}
		if(CommonTypes::getBool($in) && CommonTypes::getBool($in)){
			$this->blockActions = [];
			for($i = 0, $max = VarInt::readUnsignedInt($in); $i < $max; ++$i){
				$actionType = VarInt::readSignedInt($in);
				$this->blockActions[] = match(true){
					PlayerBlockActionWithBlockInfo::isValidActionType($actionType) => PlayerBlockActionWithBlockInfo::read($in, $actionType),
					$actionType === PlayerAction::STOP_BREAK => new PlayerBlockActionStopBreak(),
					default => throw new PacketDecodeException("Unexpected block action type $actionType")
				};
			}
		}
		if(CommonTypes::getBool($in) && CommonTypes::getBool($in)){
			$vehicleRotation = CommonTypes::getVector2($in);
		}
		if(CommonTypes::getBool($in) && CommonTypes::getBool($in)){
			$predictedVehicle = VarInt::readSignedLong($in);
		}
		if(isset($vehicleRotation) || isset($predictedVehicle)){
			$this->vehicleInfo = new PlayerAuthInputVehicleInfo($vehicleRotation?->getX(), $vehicleRotation?->getY(), $predictedVehicle ?? 0);
		}

		$this->analogMoveVecX = LE::readFloat($in);
		$this->analogMoveVecZ = LE::readFloat($in);
		$this->cameraOrientation = CommonTypes::getVector3($in);
		$this->rawMove = CommonTypes::getVector2($in);
	}

	protected function encodePayload(ByteBufferWriter $out, int $protocolId) : void{
		$inputFlags = $this->inputFlags;

		if($this->vehicleInfo !== null && $protocolId >= ProtocolInfo::PROTOCOL_1_20_60){
			$inputFlags->set(PlayerAuthInputFlags::IN_CLIENT_PREDICTED_VEHICLE, true);
		}

		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			$this->encodePayload2168($out);
			return;
		}

		LE::writeFloat($out, $this->pitch);
		LE::writeFloat($out, $this->yaw);
		CommonTypes::putVector3($out, $this->position);
		LE::writeFloat($out, $this->moveVecX);
		LE::writeFloat($out, $this->moveVecZ);
		LE::writeFloat($out, $this->headYaw);
		$this->inputFlags->write($out, $protocolId >= ProtocolInfo::PROTOCOL_1_21_50 ? PlayerAuthInputFlags::NUMBER_OF_FLAGS : 64);
		VarInt::writeUnsignedInt($out, $this->inputMode);
		VarInt::writeUnsignedInt($out, $this->playMode);
		VarInt::writeUnsignedInt($out, $this->interactionMode);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_21_40){
			CommonTypes::putVector2($out, $this->interactRotation);
		}elseif($this->playMode === PlayMode::VR){
			assert($this->vrGazeDirection !== null);
			CommonTypes::putVector3($out, $this->vrGazeDirection);
		}
		VarInt::writeUnsignedLong($out, $this->tick);
		CommonTypes::putVector3($out, $this->delta);
		if($this->itemInteractionData !== null){
			$this->itemInteractionData->write($out);
		}
		if($this->itemStackRequest !== null){
			$this->itemStackRequest->write($out, $protocolId);
		}
		if($this->blockActions !== null){
			VarInt::writeSignedInt($out, count($this->blockActions));
			foreach($this->blockActions as $blockAction){
				VarInt::writeSignedInt($out, $blockAction->getActionType());
				$blockAction->write($out);
			}
		}
		if($this->vehicleInfo !== null && $protocolId >= ProtocolInfo::PROTOCOL_1_20_60){
			$this->vehicleInfo->write($out, $protocolId);
		}
		LE::writeFloat($out, $this->analogMoveVecX);
		LE::writeFloat($out, $this->analogMoveVecZ);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_21_40){
			CommonTypes::putVector3($out, $this->cameraOrientation);
			if($protocolId >= ProtocolInfo::PROTOCOL_1_21_50){
				CommonTypes::putVector2($out, $this->rawMove);
			}
		}
	}

	private function encodePayload2168(ByteBufferWriter $out) : void{
		LE::writeFloat($out, $this->pitch);
		LE::writeFloat($out, $this->yaw);
		CommonTypes::putVector3($out, $this->position);
		LE::writeFloat($out, $this->moveVecX);
		LE::writeFloat($out, $this->moveVecZ);
		LE::writeFloat($out, $this->headYaw);

		$setIndexes = [];
		for($i = 0, $flagsLength = min($this->inputFlags->getLength(), PlayerAuthInputFlags::NUMBER_OF_FLAGS_1_26_40); $i < $flagsLength; ++$i){
			if($this->inputFlags->get($i)){
				$setIndexes[] = $i;
			}
		}
		CommonTypes::putBool($out, count($setIndexes) > 0);
		if(count($setIndexes) > 0){
			VarInt::writeUnsignedInt($out, count($setIndexes));
			foreach($setIndexes as $index){
				VarInt::writeSignedInt($out, $index);
			}
		}

		VarInt::writeUnsignedInt($out, $this->inputMode);
		VarInt::writeUnsignedInt($out, $this->playMode);
		VarInt::writeSignedInt($out, $this->interactionMode);
		CommonTypes::putVector2($out, $this->interactRotation);
		VarInt::writeUnsignedLong($out, $this->tick);
		CommonTypes::putVector3($out, $this->delta);

		CommonTypes::putBool($out, true);
		if($this->itemInteractionData !== null){
			CommonTypes::putBool($out, true);
			$this->itemInteractionData->write($out);
		}else{
			CommonTypes::putBool($out, false);
		}

		CommonTypes::putBool($out, true);
		if($this->itemStackRequest !== null){
			CommonTypes::putBool($out, true);
			$this->itemStackRequest->write($out, ProtocolInfo::PROTOCOL_1_26_40);
		}else{
			CommonTypes::putBool($out, false);
		}

		CommonTypes::putBool($out, true);
		if($this->blockActions !== null){
			CommonTypes::putBool($out, true);
			VarInt::writeUnsignedInt($out, count($this->blockActions));
			foreach($this->blockActions as $blockAction){
				VarInt::writeSignedInt($out, $blockAction->getActionType());
				$blockAction->write($out);
			}
		}else{
			CommonTypes::putBool($out, false);
		}

		CommonTypes::putBool($out, true);
		if($this->vehicleInfo !== null && $this->vehicleInfo->getVehicleRotationX() !== null){
			CommonTypes::putBool($out, true);
			CommonTypes::putVector2($out, new Vector2($this->vehicleInfo->getVehicleRotationX(), $this->vehicleInfo->getVehicleRotationZ()));
		}else{
			CommonTypes::putBool($out, false);
		}

		CommonTypes::putBool($out, true);
		if($this->vehicleInfo !== null){
			CommonTypes::putBool($out, true);
			VarInt::writeSignedLong($out, $this->vehicleInfo->getPredictedVehicleActorUniqueId());
		}else{
			CommonTypes::putBool($out, false);
		}

		LE::writeFloat($out, $this->analogMoveVecX);
		LE::writeFloat($out, $this->analogMoveVecZ);
		CommonTypes::putVector3($out, $this->cameraOrientation);
		CommonTypes::putVector2($out, $this->rawMove);
	}

	public function handle(PacketHandlerInterface $handler) : bool{
		return $handler->handlePlayerAuthInput($this);
	}
}
