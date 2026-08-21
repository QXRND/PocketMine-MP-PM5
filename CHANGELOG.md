# SyntaxStudios PocketMine-MP Changelog

This changelog covers changes made in this fork on top of [NetherGamesMC/PocketMine-MP](https://github.com/NetherGamesMC/PocketMine-MP). For the upstream PocketMine-MP changelog (protocol/version history up to the point this fork was based on), see the [`changelogs/`](changelogs/) directory inherited from upstream.

## v5.44.2-syntax.1

First public release. Everything below was built on top of NetherGamesMC's multi-protocol base after `pmmp/PocketMine-MP` was archived upstream (2026-07-09) and Bedrock 1.26.40 shipped with no multi-protocol fork supporting it yet.

### Native Bedrock 1.26.40/42 (protocol 2168) support

The headline change: this server now natively speaks protocol 2168 (Bedrock 1.26.40/1.26.42) *alongside* 1.26.20-33 (975/1001) and 1.21.111 (844) in the same running server - no translation proxy involved. `NetherGamesMC/BedrockProtocol` (now merged directly into this repo, see below) had no 2168 support at all going in; every packet/type change below was reverse-engineered from scratch.

Notable fixes along the way, roughly in the order they were found:

- **`StartGamePacket`**: `blockNetworkIdsAreHashes` was hardcoded `false`, causing real client-side crashes (not just disconnects) because the client interpreted block network IDs as palette indices instead of hashes.
- **`StartGamePacket.isLoggingChat`**: field removed by Mojang in 2168 but was still being sent - this was the single fix that first let a 2168 client complete login instead of hanging indefinitely.
- **Block state hashing**: `BlockStateDictionaryEntry` FNV1a-32 hash computation, verified byte-for-byte against an independent implementation.
- **`CreativeContentPacket`**: full 108-group/1695-item creative inventory re-encoded and verified byte-perfect against CloudburstMC's reference decoder for 2168.
- **`CraftingDataPacket`**: two separate format changes - `ShapedRecipe` needs an explicit ingredient-count prefix in 2168 (not redundant, `helper.readArray()` always expects it), and `ItemDescriptorType`'s "name" variant switched from a numeric id+meta pair to a full string identifier (`minecraft:stick`) plus signed aux value.
- **Item stack encoding**: several related but distinct bugs across `AddItemActorPacket`/`AddPlayerPacket`/`CraftingEventPacket`/`InventoryContentPacket` - the "air" shortcut encoding no longer applies in 2168 (air now needs the full item header like any other item), and three packets were using the wrong item-stack format entirely (`getItemStackWrapper` instead of the descriptor format 2168 actually requires).
- **`PlayerAuthInputPacket`**: rewritten for 2168 - input flags moved from a dense fixed-width bitset to a sparse list of signed VarInt indices, `interactionMode` became signed, several optional sections gained an extra wrapping bool, and the flag enum grew a new value (`INTERNAL_UPDATE`) that a real client sends and that crashed decoding if unhandled.
- **`ItemStackRequestPacket`/`ItemStackResponsePacket`**: a chain of five encoding bugs (redundant legacy byte per action, `stackNetworkId` switching between VarInt/fixed-int depending on request vs. response, two new wrapping bools in the response, a completely different item descriptor format specific to the 2x2 "craft without table" action) and, most impactful, a full renumbering of `ItemStackRequestActionType` - 2168 assigns wire IDs from scratch instead of inheriting them, so every crafting/inventory action beyond the first few was silently being misinterpreted as a different action entirely.
- **`PlayerListPacket` join-order sensitivity**: a 2168 client silently disconnects if it receives its *own* `PlayerListPacket` entry before finishing its own spawn sequence - moved that entry to be sent from the post-spawn handler instead of pre-spawn, found via live bisection after independent byte-level verification (CloudburstMC, BetterAltay, official Mojang docs) had already ruled out a wire-format bug.
- **`NetworkItemStackDescriptor` variant field**: 2168 added a "variant" field to the wire format ahead of the stack ID in a subset of protocols (975-1001), missed on the initial port; a follow-up fix that copied the official upstream diff too literally also flipped the stack ID itself from signed to unsigned VarInt, breaking all inventory interaction across every protocol using that function until corrected. Fixing this also resolved a long-standing "armor invisible between real players" limitation on 2168 that had previously been attributed to an unfixable client-side restriction - it was the same encoding bug.
- **`AddPlayerPacket` carried item**: per Mojang's official r/26_u4 changelog, this field must omit the item-stack net ID entirely and have its NBT stripped to just an empty enchantment marker - our general item conversion path didn't do either, which is likely to matter for any player-typed entity spawning with a non-air held item on 2168.

**Known client-side limitation, not fixable server-side:** a default/unmodified Bedrock skin (the classic "Steve/Alex" auto-assigned identity, or the modern Persona system) crashes a protocol 2168 viewer - confirmed via extensive live A/B testing (disabling every candidate fix and testing with a real client) that this is a genuine 2168 client policy, not a server-side encoding issue. The server rejects logins with such skins on 2168 and hides other-protocol players who have one from a 2168 viewer's player list.

### Other fixes

- Player-list identity (`PlayerListPacket`) now uses the player's plain username instead of `getDisplayName()` - a rank/prefix plugin's colored display name was leaking into Bedrock's native "@" chat-mention autocomplete, producing garbled mentions for any player with a rank.

### Repository changes

- Merged `nethergamesmc/bedrock-protocol` (our `SyntaxStudiosRE/BedrockProtocol` fork) directly into this repository under `src/network/mcpe/protocol/`, removing the separate composer dependency and the friction that came with it (manual composer.lock hash pinning after every change, a standalone clone just to push vendor fixes, and repeated dependency-resolution failures for the private fork's dist downloads).
