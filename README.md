# QXRND - PocketMine-MP PM5

![Platform](https://img.shields.io/badge/platform-Minecraft%20Bedrock-55C2E6)
![API](https://img.shields.io/badge/API-5.44.5-2F81F7)
![Protocol](https://img.shields.io/badge/protocol-2169-6F42C1)
![Runtime](https://img.shields.io/badge/PHP-8.2-777BB4)

QXRND - PocketMine-MP PM5 is a downstream PocketMine-MP distribution targeting the PM5 plugin API and simultaneous Minecraft Bedrock support for 1.21.111, 1.21.114, 1.26.40, 1.26.42, 1.26.44 and 1.26.45. It is derived from the PocketMine-MP ecosystem and carries QXRND-specific protocol, packaging, branding, and operational changes. It is not an official upstream PocketMine-MP release and is not affiliated with Mojang, Microsoft, or the PocketMine-MP maintainers.

This repository is intended for maintainers and operators who require a PM5-compatible server runtime with multiversion Bedrock compatibility and a reproducible PHAR distribution.

## Release profile

| Component | Value |
|---|---|
| Distribution | QXRND - PocketMine-MP PM5 |
| PocketMine-MP API line | 5.44.5 |
| Bedrock network versions | 1.21.111, 1.21.114, 1.26.40, 1.26.42, 1.26.44, 1.26.45 |
| Bedrock protocols | 844, 2168, 2169 |
| PHP runtime | PHP 8.2, x86_64 |
| Stable release | [`v5.44.5-qxrnd.10`](https://github.com/QXRND/PocketMine-MP-PM5/releases/tag/v5.44.5-qxrnd.10) |
| Distribution asset | [`PocketMine-MP.phar`](https://github.com/QXRND/PocketMine-MP-PM5/releases/download/v5.44.5-qxrnd.10/PocketMine-MP.phar) |
| Author and maintainer | **DevPapo** |

## Upstream lineage and design boundaries

The PM5 branch preserves the PocketMine-MP server architecture: plugin lifecycle and API contracts, command dispatch, permissions, scheduler semantics, world management, RakNet transport integration, resource-pack negotiation, and the server tick loop. QXRND changes are maintained as downstream modifications rather than presented as upstream-compatible guarantees.

The Bedrock layer accepts multiple client versions simultaneously. Versions 1.21.111 and 1.21.114 use protocol 844; versions 1.26.40, 1.26.42 and 1.26.44 use protocol 2168; and version 1.26.45 uses protocol 2169. Packet encoding is selected per session protocol ID, while the server advertises 1.26.45 as its current version. Plugin authors should distinguish between the documented PM5 API and internal packet or data classes. Code relying on implementation details may require changes when protocol data or dependency revisions are updated.

## QXRND modifications

The distribution contains the following operational and source-level changes:

- Simultaneous Minecraft Bedrock support for 1.21.111/1.21.114 (protocol 844), 1.26.40/1.26.42/1.26.44 (protocol 2168), and 1.26.45 (protocol 2169).
- QXRND branding in server metadata, version output, startup information, and crash reports.
- English QXRND output for `/ver`, `/about`, and `/status`, without emojis or decorative diamonds.
- Discord support in `/ver` and `/about`; `/status` intentionally omits the support link.
- Quick gamemode aliases: `/gma`, `/gmsp`, `/gmc`, and `/gms` for Adventure, Spectator, Creative, and Survival.
- Gamemode aliases using the normal `/gamemode` permission nodes rather than a separate permission namespace.
- Configurable `/say` prefix through `settings.say-prefix`, defaulting to `QXRND`.
- Minimal PHAR packaging to reduce disk amplification during Pterodactyl's PHAR extraction process.
- A release-oriented distribution model with one stable release asset named `PocketMine-MP.phar`.

## Runtime requirements

The published build requires a 64-bit Linux environment with PHP 8.2 and the extensions supplied by the PMMP-compatible PHP binary distribution. The QXRND Pterodactyl Egg is the recommended deployment path because it installs the expected runtime and invokes the PHAR through `bin/php7/bin/php`.

Online-mode servers require outbound connectivity for Xbox Live authentication and Bedrock key retrieval. UDP port exposure, filesystem permissions, DNS, firewall policy, and host-level resource limits are outside the server binary's control.

## Installation

Download the stable PHAR and run it with the matching PHP runtime:

```bash
curl -fL -o PocketMine-MP.phar \
  https://github.com/QXRND/PocketMine-MP-PM5/releases/download/v5.44.5-qxrnd.10/PocketMine-MP.phar
php8.2 PocketMine-MP.phar --no-wizard
```

For Pterodactyl, import [`egg-pmmp.json`](https://github.com/QXRND/PocketMine-MP-Egg/blob/main/egg-pmmp.json), select `PM5` in the `VERSION` variable, and use **Reinstall Server** when replacing an existing installation. Changing the variable and pressing **Start** does not rerun the installation script.

## Configuration

The generated `pocketmine.yml` supports the following setting:

```yaml
settings:
  say-prefix: "QXRND"
```

The value controls the prefix emitted by `/say`. Existing installations may require the key to be added manually under the `settings` mapping.

## Build and packaging

The repository contains the source tree, Composer lockfile, and build configuration required to produce the distribution PHAR. A minimal distribution build can be invoked with:

```bash
composer install --no-interaction
RYXMC_MINIMAL_PHAR=1 composer run make-server --no-interaction
```

The output artifact is `PocketMine-MP.phar`. The minimal build mode excludes unnecessary historical Bedrock data to reduce extraction-time disk consumption; it does not remove the data required by the supported protocol families.

## Plugin and protocol compatibility

PM5 plugins should target the PM5 API contract and avoid depending on private implementation details. Plugins that register packet listeners, construct protocol packets, manipulate NBT directly, or assume historical item and block identifiers require integration testing against each supported protocol family, especially 844, 2168 and 2169.

Operators should validate authentication, resource-pack negotiation, inventory transactions, entity metadata, block-state translation, and custom packet handlers after changing the PHAR or dependency lockfile.

## Release and update policy

The stable release asset is updated in place for small fixes instead of creating a new GitHub release for every change. Consumers should always download the asset from the stable release link below and verify that the asset name is exactly `PocketMine-MP.phar`.

## Support and distribution links

| Resource | Link |
|---|---|
| Source repository | [QXRND/PocketMine-MP-PM5](https://github.com/QXRND/PocketMine-MP-PM5) |
| Stable release | [`v5.44.5-qxrnd.10`](https://github.com/QXRND/PocketMine-MP-PM5/releases/tag/v5.44.5-qxrnd.10) |
| Direct PHAR download | [`PocketMine-MP.phar`](https://github.com/QXRND/PocketMine-MP-PM5/releases/download/v5.44.5-qxrnd.10/PocketMine-MP.phar) |
| Pterodactyl Egg | [QXRND/PocketMine-MP-Egg](https://github.com/QXRND/PocketMine-MP-Egg) |
| Technical support and invitation | [QXRND Discord](https://discord.gg/qhUXn72rGB) |
| Upstream lineage | [pmmp/PocketMine-MP](https://github.com/pmmp/PocketMine-MP) |

## Credits and legal notice

The QXRND downstream distribution, branding, release engineering, and compatibility work are maintained and published by **DevPapo**. PocketMine-MP is the upstream project from which this distribution is derived. Minecraft, Minecraft Bedrock, and related marks belong to their respective owners. This project is neither affiliated with nor endorsed by Mojang or Microsoft.
