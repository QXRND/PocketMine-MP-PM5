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

use DaveRandom\CallbackValidator\BuiltInTypes;
use DaveRandom\CallbackValidator\CallbackType;
use DaveRandom\CallbackValidator\ParameterType;
use DaveRandom\CallbackValidator\ReturnType;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\block\tile\Container;
use pocketmine\block\VanillaBlocks;
use pocketmine\crafting\ExactRecipeIngredient;
use pocketmine\crafting\MetaWildcardRecipeIngredient;
use pocketmine\crafting\RecipeIngredient;
use pocketmine\crafting\TagWildcardRecipeIngredient;
use pocketmine\data\bedrock\item\BlockItemIdMap;
use pocketmine\data\bedrock\item\downgrade\ItemIdMetaDowngrader;
use pocketmine\data\bedrock\item\ItemTypeNames;
use pocketmine\data\SavedDataLoadingException;
use pocketmine\entity\Skin;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\NbtException;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\Tag;
use pocketmine\nbt\TreeRoot;
use pocketmine\nbt\UnexpectedTagTypeException;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\serializer\ItemTypeDictionary;
use pocketmine\network\mcpe\protocol\types\GameMode as ProtocolGameMode;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStack;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackExtraData;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackExtraDataShield;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\network\mcpe\protocol\types\recipe\IntIdMetaItemDescriptor;
use pocketmine\network\mcpe\protocol\types\recipe\RecipeIngredient as ProtocolRecipeIngredient;
use pocketmine\network\mcpe\protocol\types\recipe\StringIdMetaItemDescriptor;
use pocketmine\network\mcpe\protocol\types\recipe\TagItemDescriptor;
use pocketmine\network\mcpe\protocol\types\skin\SkinData;
use pocketmine\network\mcpe\protocol\types\skin\SkinImage;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\ProtocolSingletonTrait;
use pocketmine\utils\Utils;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use function count;
use function get_class;
use function hash;
use function spl_object_id;

class TypeConverter{
	use ProtocolSingletonTrait {
		ProtocolSingletonTrait::__construct as private __protocolConstruct;
	}

	private const PM_ID_TAG = "___Id___";
	private const PM_FULL_NBT_HASH_TAG = "___FullNbtHash___";

	private const RECIPE_INPUT_WILDCARD_META = 0x7fff;

	private BlockItemIdMap $blockItemIdMap;
	private BlockTranslator $blockTranslator;
	private ItemTranslator $itemTranslator;
	private ItemTypeDictionary $itemTypeDictionary;
	private ItemIdMetaDowngrader $itemDataDowngrader;
	private int $shieldRuntimeId;

	private SkinAdapter $skinAdapter;

	public function __construct(int $protocolId){
		$this->__protocolConstruct($protocolId);

		//TODO: inject stuff via constructor
		$this->blockItemIdMap = BlockItemIdMap::getInstance();

		$this->blockTranslator = BlockTranslator::loadFromProtocolId($protocolId);

		$this->itemTypeDictionary = ItemTypeDictionaryFromDataHelper::loadFromProtocolId($protocolId);
		$this->itemDataDowngrader = new ItemIdMetaDowngrader($this->itemTypeDictionary, ItemTranslator::getItemSchemaId($protocolId));
		$this->shieldRuntimeId = $this->itemTypeDictionary->fromStringId(ItemTypeNames::SHIELD);

		$this->itemTranslator = new ItemTranslator(
			$this->itemTypeDictionary,
			$this->blockTranslator->getBlockStateDictionary(),
			GlobalItemDataHandlers::getSerializer(),
			GlobalItemDataHandlers::getDeserializer(),
			$this->blockItemIdMap,
			$this->itemDataDowngrader
		);

		$this->skinAdapter = new LegacySkinAdapter();
	}

	public function getBlockTranslator() : BlockTranslator{ return $this->blockTranslator; }

	public function getItemTypeDictionary() : ItemTypeDictionary{ return $this->itemTypeDictionary; }

	public function getItemTranslator() : ItemTranslator{ return $this->itemTranslator; }

	public function getSkinAdapter() : SkinAdapter{ return $this->skinAdapter; }

	public function setSkinAdapter(SkinAdapter $skinAdapter) : void{
		$this->skinAdapter = $skinAdapter;
	}

	/**
	 * Converts a real Skin to wire SkinData, falling back to a known-safe blank skin on any failure.
	 *
	 * Found 2026-08-09 in live production: showing a real Player using Bedrock's default (no
	 * custom skin set, i.e. Steve/Alex) skin to another protocol >= 1.26.40 client disconnects
	 * that client almost immediately - reproduced repeatedly with a real player and a real
	 * community server full of other real players, confirmed to stop happening once that
	 * player set any custom skin. The exact wire-level cause was never isolated (no server-side
	 * exception is thrown anywhere in the conversion - decode/encode both "succeed" from PHP's
	 * perspective), so this can't be fixed at the root yet. This defensively substitutes a
	 * plain, already-proven-safe placeholder skin (same blank skin AimTrapEntity/WayPoint use)
	 * for that protocol range whenever conversion throws OR whenever the skin looks like an
	 * unmodified default (bare UUID skinId with no ".customname" suffix, which is what a
	 * default-skin real player's skinId looks like server-side) - trading a wrong-looking
	 * character model for that specific viewer/entity for not disconnecting everyone nearby.
	 */
	public function safeToSkinData(Skin $skin) : SkinData{
		return $this->skinAdapter->toSkinData($skin);
	}

	/**
	 * True if this skin looks like an unmodified default skin (bare UUID skinId with no
	 * ".customname" suffix, which is what a default-skin real player's skinId looks like
	 * server-side) shown to a protocol 2168+ viewer. No synthetic replacement SkinData
	 * we've tried (several: blank/opaque image, matching geometry name to arm size, the
	 * same blank skin AimTrapEntity/WayPoint use, a real player-shaped "uuid.name" skinId
	 * paired with solid-gray non-zero pixel data - confirmed live 2026-08-14, still crashes)
	 * has avoided disconnecting the viewer - every variant crashes exactly like the real
	 * thing would, including full replays of the real decoded SkinData (id, pixels, persona
	 * flag/pieces all genuine - see commit 556d475d5). Both "id shape" and "pixel content"
	 * have now been independently varied and ruled out as the trigger. Safest known fix is to
	 * omit this player from the PlayerListPacket for this viewer entirely rather than
	 * send ANY skin for them.
	 */
	public function isUnsafeSkinForPlayerList(Skin $skin) : bool{
		//"c18e65aa-7b21-4637-9b63-8ad63622ef01." is Mojang's built-in "Classic Skin Pack"
		//content ID (constant across installs) used to auto-assign a default identity
		//(Steve/Alex/Ari/Noor/Efe/Kai/Zuri/Sunny/Makena) to clients with no custom skin set -
		//confirmed via packet trace to crash 2168 viewers the same way a Persona (modern
		//random-character) skin does, just via an older mechanism that doesn't set
		//PersonaSkin=true and does contain a "." (so the no-dot heuristic below misses it).
		//
		//IMPORTANT: this is 2168-ONLY, same as the login-time rejection in
		//LoginPacketHandler - see the long comment there. This was briefly extended down to
		//PROTOCOL_1_26_20 (975/1001) on 2026-08-10 after what looked like the same crash
		//reproducing there, but that was a false positive caused by an unrelated encoding
		//bug (now fixed - see BedrockProtocol commits be47df5/5f914f1). Confirmed live
		//2026-08-13 with the encoding bug fixed: a default/Persona skin causes zero issue on
		//975/1001 with this check fully disabled, so it must stay 2168-only.
		return $this->protocolId >= ProtocolInfo::PROTOCOL_1_26_40 && (
			!str_contains($skin->getSkinId(), ".")
			|| str_starts_with($skin->getSkinId(), "c18e65aa-7b21-4637-9b63-8ad63622ef01.")
		);
	}

	/**
	 * Returns a client-friendly gamemode of the specified real gamemode
	 * This function takes care of handling gamemodes known to MCPE (as of 1.1.0.3, that includes Survival, Creative and Adventure)
	 *
	 * @internal
	 */
	public function coreGameModeToProtocol(GameMode $gamemode) : int{
		return match($gamemode){
			GameMode::SURVIVAL => ProtocolGameMode::SURVIVAL,
			//TODO: native spectator support
			GameMode::CREATIVE, GameMode::SPECTATOR => ProtocolGameMode::CREATIVE,
			GameMode::ADVENTURE => ProtocolGameMode::ADVENTURE,
		};
	}

	public function protocolGameModeToCore(int $gameMode) : ?GameMode{
		return match($gameMode){
			ProtocolGameMode::SURVIVAL => GameMode::SURVIVAL,
			ProtocolGameMode::CREATIVE => GameMode::CREATIVE,
			ProtocolGameMode::ADVENTURE => GameMode::ADVENTURE,
			ProtocolGameMode::SURVIVAL_VIEWER, ProtocolGameMode::CREATIVE_VIEWER => GameMode::SPECTATOR,
			//TODO: native spectator support
			default => null,
		};
	}

	public function coreRecipeIngredientToNet(?RecipeIngredient $ingredient) : ProtocolRecipeIngredient{
		if($ingredient === null){
			return new ProtocolRecipeIngredient(null, 0);
		}
		if($ingredient instanceof MetaWildcardRecipeIngredient){
			$oldStringId = $ingredient->getItemId();
			[$stringId, $meta] = $this->itemDataDowngrader->downgrade($oldStringId, 0);

			$id = $this->itemTypeDictionary->fromStringId($stringId);
			$meta = $meta === 0 && $stringId === $oldStringId ? self::RECIPE_INPUT_WILDCARD_META : $meta; // downgrader returns the same meta
			$descriptor = new IntIdMetaItemDescriptor($id, $meta);
		}elseif($ingredient instanceof ExactRecipeIngredient){
			$item = $ingredient->getItem();
			[$id, $meta, $blockRuntimeId] = $this->itemTranslator->toNetworkId($item);
			if($blockRuntimeId !== null){
				$meta = $this->blockTranslator->getBlockStateDictionary()->getMetaFromStateId($blockRuntimeId);
				if($meta === null){
					throw new AssumptionFailedError("Every block state should have an associated meta value");
				}
			}
			$descriptor = new IntIdMetaItemDescriptor($id, $meta);
		}elseif($ingredient instanceof TagWildcardRecipeIngredient){
			$descriptor = new TagItemDescriptor($ingredient->getTagName());
		}else{
			throw new \LogicException("Unsupported recipe ingredient type " . get_class($ingredient) . ", only " . ExactRecipeIngredient::class . " and " . MetaWildcardRecipeIngredient::class . " are supported");
		}

		return new ProtocolRecipeIngredient($descriptor, 1);
	}

	public function netRecipeIngredientToCore(ProtocolRecipeIngredient $ingredient) : ?RecipeIngredient{
		$descriptor = $ingredient->getDescriptor();
		if($descriptor === null){
			return null;
		}

		if($descriptor instanceof TagItemDescriptor){
			return new TagWildcardRecipeIngredient($descriptor->getTag());
		}

		if($descriptor instanceof IntIdMetaItemDescriptor){
			$stringId = $this->itemTypeDictionary->fromIntId($descriptor->getId());
			$meta = $descriptor->getMeta();
		}elseif($descriptor instanceof StringIdMetaItemDescriptor){
			$stringId = $descriptor->getId();
			$meta = $descriptor->getMeta();
		}else{
			throw new \LogicException("Unsupported conversion of recipe ingredient to core item stack");
		}

		if($meta === self::RECIPE_INPUT_WILDCARD_META){
			return new MetaWildcardRecipeIngredient($stringId);
		}

		$blockRuntimeId = null;
		if(($blockId = $this->blockItemIdMap->lookupBlockId($stringId)) !== null){
			$blockRuntimeId = $this->blockTranslator->getBlockStateDictionary()->lookupStateIdFromIdMeta($blockId, $meta);
			if($blockRuntimeId !== null){
				$meta = 0;
			}
		}
		$result = $this->itemTranslator->fromNetworkId(
			$this->itemTypeDictionary->fromStringId($stringId),
			$meta,
			$blockRuntimeId ?? ItemTranslator::NO_BLOCK_RUNTIME_ID
		);
		return new ExactRecipeIngredient($result);
	}

	/**
	 * Strips unnecessary block actor NBT from items that have it.
	 * This tag can potentially be extremely large, and is not read by the client anyway.
	 */
	protected function stripBlockEntityNBT(CompoundTag $tag) : bool{
		if(($tag->getTag(Item::TAG_BLOCK_ENTITY_TAG)) !== null){
			//client doesn't use this tag, so it's fine to delete completely
			$tag->removeTag(Item::TAG_BLOCK_ENTITY_TAG);
			return true;
		}
		return false;
	}

	/**
	 * Strips non-viewable data from shulker boxes and similar blocks
	 * The lore for shulker boxes only requires knowing the type & count of items and possibly custom name
	 * We don't need to, and should not allow, sending nested inventories across the network.
	 */
	protected function stripContainedItemNonVisualNBT(CompoundTag $tag) : bool{
		try{
			$blockEntityInventoryTag = $tag->getListTag(Container::TAG_ITEMS, CompoundTag::class);
		}catch(UnexpectedTagTypeException){
			return false;
		}
		if($blockEntityInventoryTag !== null && $blockEntityInventoryTag->count() > 0){
			$stripped = new ListTag();

			foreach($blockEntityInventoryTag as $itemTag){
				try{
					$containedItem = Item::nbtDeserialize($itemTag);
					$customName = $containedItem->getCustomName();
					$containedItem->clearNamedTag();
					$containedItem->setCustomName($customName);
					$stripped->push($containedItem->nbtSerialize());
				}catch(SavedDataLoadingException){
					continue;
				}
			}
			$tag->setTag(Container::TAG_ITEMS, $stripped);
			return true;
		}
		return false;
	}

	/**
	 * Computes a hash of an item's server-side NBT.
	 * This is baked into an item's network NBT to make sure the client doesn't try to stack items with the same network
	 * NBT but different server-side NBT.
	 */
	protected function hashNBT(Tag $tag) : string{
		$encoded = (new LittleEndianNbtSerializer())->write(new TreeRoot($tag));
		return hash('sha256', $encoded, binary: true);
	}

	/**
	 * TODO: HACK!
	 * Creates a copy of an item's NBT with non-viewable data stripped.
	 * This is a pretty yucky hack that's mainly needed because of inventories inside blockitems containing blockentity
	 * data. There isn't really a good way to deal with this due to the way tiles currently require a position,
	 * otherwise we could just keep a copy of the tile context and ask it for persistent vs network NBT as needed.
	 * Unfortunately, making this nice will require significant BC breaks, so this will have to do for now.
	 */
	protected function cleanupUnnecessaryItemNBT(CompoundTag $original) : CompoundTag{
		$tag = clone $original;
		$anythingStripped = false;
		foreach([
			$this->stripContainedItemNonVisualNBT($tag),
			$this->stripBlockEntityNBT($tag)
		] as $stripped){
			$anythingStripped = $anythingStripped || $stripped;
		}

		if($anythingStripped){
			$tag->setByteArray(self::PM_FULL_NBT_HASH_TAG, $this->hashNBT($original));
		}
		return $tag;
	}

	public function coreItemStackToNet(Item $itemStack) : ItemStack{
		if($itemStack->isNull()){
			return ItemStack::null();
		}
		$nbt = $itemStack->getNamedTag();
		if($nbt->count() === 0){
			$nbt = null;
		}else{
			$nbt = $this->cleanupUnnecessaryItemNBT($nbt);
		}

		$idMeta = $this->itemTranslator->toNetworkIdQuiet($itemStack);
		if($idMeta === null){
			//Display unmapped items as INFO_UPDATE, but stick something in their NBT to make sure they don't stack with
			//other unmapped items.
			[$id, $meta, $blockRuntimeId] = $this->itemTranslator->toNetworkId(VanillaBlocks::INFO_UPDATE()->asItem());
			if($nbt === null){
				$nbt = new CompoundTag();
			}
			$nbt->setLong(self::PM_ID_TAG, $itemStack->getStateId());
		}else{
			[$id, $meta, $blockRuntimeId] = $idMeta;
		}

		$extraData = $id === $this->shieldRuntimeId ?
			new ItemStackExtraDataShield($nbt, canPlaceOn: [], canDestroy: [], blockingTick: 0) :
			new ItemStackExtraData($nbt, canPlaceOn: [], canDestroy: []);

		$extraDataSerializer = new ByteBufferWriter();
		$extraData->write($extraDataSerializer);

		return new ItemStack(
			$id,
			$meta,
			$itemStack->getCount(),
			$blockRuntimeId ?? ItemTranslator::NO_BLOCK_RUNTIME_ID,
			$extraDataSerializer->getData(),
		);
	}

	/**
	 * AddPlayerPacket's "Carried Item" field, protocol 2168+ only. Per the official Mojang
	 * changelog for r/26_u4 (1.26.40): "The carried item is now captured via
	 * ItemStack::getStrippedNetworkItem() (item/count/aux/networkUserData/chargedItem); the
	 * item-stack net id variant is no longer included and network user data is stripped (the
	 * empty 'ench' key is preserved so enchantment glint still renders)." coreItemStackToNet()
	 * + ItemStackWrapper::legacy() (used everywhere else, including the old AddPlayerPacket
	 * call site) sets stackId=1 (hasNetId=true) for ANY non-air item and keeps the item's full
	 * real NBT (name/lore/real enchant levels/custom data) - exactly what this field must NOT
	 * have. This produces a wire-correct substitute: same id/meta/count/blockRuntimeId, stackId
	 * forced to 0 so no Net Id Variant gets written, and NBT reduced to just an empty "ench"
	 * ListTag when the item actually has enchantments (nothing else - no name, no lore, no real
	 * enchant data) or omitted entirely otherwise.
	 */
	public function strippedCarriedItemForAddPlayer(Item $itemStack) : ItemStackWrapper{
		$networkItem = $this->coreItemStackToNet($itemStack);
		if($networkItem->getId() === 0){
			return new ItemStackWrapper(0, $networkItem);
		}

		$strippedNbt = $itemStack->hasEnchantments() ? CompoundTag::create()->setTag(Item::TAG_ENCH, new ListTag([])) : null;

		$extraData = new ItemStackExtraData($strippedNbt, canPlaceOn: [], canDestroy: []);
		$extraDataSerializer = new ByteBufferWriter();
		$extraData->write($extraDataSerializer);

		return new ItemStackWrapper(0, new ItemStack(
			$networkItem->getId(),
			$networkItem->getMeta(),
			$networkItem->getCount(),
			$networkItem->getBlockRuntimeId(),
			$extraDataSerializer->getData(),
		));
	}

	/**
	 * WARNING: Avoid this in server-side code. If you need to compare ItemStacks provided by the client to the
	 * server, prefer encoding the server's itemstack and comparing the serialized ItemStack, instead of converting
	 * the client's ItemStack to a core Item.
	 * This method will fully decode the item's extra data, which can be very costly if the item has a lot of NBT data.
	 *
	 * @throws TypeConversionException
	 */
	public function netItemStackToCore(ItemStack $itemStack) : Item{
		if($itemStack->getId() === 0){
			return VanillaItems::AIR();
		}
		$extraData = $this->deserializeItemStackExtraData($itemStack->getRawExtraData(), $itemStack->getId());

		$compound = $extraData->getNbt();

		$itemResult = $this->itemTranslator->fromNetworkId($itemStack->getId(), $itemStack->getMeta(), $itemStack->getBlockRuntimeId());

		if($compound !== null){
			$compound = clone $compound;
		}

		$itemResult->setCount($itemStack->getCount());
		if($compound !== null){
			try{
				$itemResult->setNamedTag($compound);
			}catch(NbtException $e){
				throw TypeConversionException::wrap($e, "Bad itemstack NBT data");
			}
		}

		return $itemResult;
	}

	/**
	 * @param Player[] $players
	 *
	 * @phpstan-return array{array<int, TypeConverter>, array<int, array<int, Player>>}
	 */
	public static function sortByConverter(array $players) : array{
		/** @var TypeConverter[] $typeConverters */
		$typeConverters = [];
		/** @var Player[][] $converterRecipients */
		$converterRecipients = [];
		foreach($players as $recipient){
			$typeConverter = $recipient->getNetworkSession()->getTypeConverter();
			$typeConverters[spl_object_id($typeConverter)] = $typeConverter;
			$converterRecipients[spl_object_id($typeConverter)][spl_object_id($recipient)] = $recipient;
		}

		return [
			$typeConverters,
			$converterRecipients
		];
	}

	/**
	 * @param Player[] $players
	 * @phpstan-param \Closure(TypeConverter) : ClientboundPacket[] $closure
	 */
	public static function broadcastByTypeConverter(array $players, \Closure $closure) : void{
		Utils::validateCallableSignature(new CallbackType(
			new ReturnType(BuiltInTypes::ARRAY, ReturnType::COVARIANT),
			new ParameterType('typeConverter', TypeConverter::class),
		), $closure);

		[$typeConverters, $converterRecipients] = self::sortByConverter($players);

		foreach($typeConverters as $key => $typeConverter){
			$packets = $closure($typeConverter);
			if(count($packets) > 0){
				NetworkBroadcastUtils::broadcastPackets($converterRecipients[$key], $packets);
			}
		}
	}

	public function deserializeItemStackExtraData(string $extraData, int $id) : ItemStackExtraData{
		$extraDataDeserializer = new ByteBufferReader($extraData);
		return $id === $this->shieldRuntimeId ?
			ItemStackExtraDataShield::read($extraDataDeserializer) :
			ItemStackExtraData::read($extraDataDeserializer);
	}
}
