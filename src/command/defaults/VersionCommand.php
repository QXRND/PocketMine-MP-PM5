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

namespace pocketmine\command\defaults;

use pocketmine\command\CommandSender;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\plugin\Plugin;
use pocketmine\utils\TextFormat;
use pocketmine\utils\Utils;
use pocketmine\VersionInfo;
use function count;
use function implode;
use function stripos;
use function strtolower;
use function substr;
use const PHP_VERSION;

class VersionCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"version",
			KnownTranslationFactory::pocketmine_command_version_description(),
			KnownTranslationFactory::pocketmine_command_version_usage(),
			["ver", "about"]
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_VERSION);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) === 0){
			$versionColor = VersionInfo::IS_DEVELOPMENT_BUILD ? TextFormat::YELLOW : TextFormat::GREEN;
			$jitMode = Utils::getOpcacheJitMode();
			$jitText = $jitMode === null ? "No disponible" : ($jitMode === 0 ? "Desactivado" : "Activado");

			$sender->sendMessage(TextFormat::DARK_AQUA . "✦ " . TextFormat::AQUA . "QXRND - PocketMine-MP" . TextFormat::WHITE . " Network");
			$sender->sendMessage(TextFormat::GRAY . "  Información del servidor");
			$sender->sendMessage(TextFormat::BLUE . "------------------------------");
			$sender->sendMessage(TextFormat::AQUA . "Servidor  " . TextFormat::WHITE . "> " . TextFormat::AQUA . VersionInfo::NAME);
			$sender->sendMessage(TextFormat::AQUA . "Versión   " . TextFormat::WHITE . "> " . $versionColor . VersionInfo::VERSION()->getFullVersion());
			$sender->sendMessage(TextFormat::AQUA . "Autor     " . TextFormat::WHITE . "> " . TextFormat::GOLD . "DevPapo");
			$sender->sendMessage(TextFormat::AQUA . "Plataforma" . TextFormat::WHITE . " > " . TextFormat::GREEN . "Minecraft Bedrock");
			$sender->sendMessage(TextFormat::AQUA . "Bedrock   " . TextFormat::WHITE . "> " . TextFormat::GREEN . ProtocolInfo::MINECRAFT_VERSION_NETWORK . TextFormat::GRAY . " (protocolo " . ProtocolInfo::CURRENT_PROTOCOL . ")");
			$sender->sendMessage(TextFormat::AQUA . "PHP       " . TextFormat::WHITE . "> " . TextFormat::GREEN . PHP_VERSION);
			$sender->sendMessage(TextFormat::AQUA . "Sistema   " . TextFormat::WHITE . "> " . TextFormat::GREEN . Utils::getOS());
			$sender->sendMessage(TextFormat::AQUA . "OPcache   " . TextFormat::WHITE . "> " . TextFormat::GREEN . "JIT " . $jitText);
			$sender->sendMessage(TextFormat::BLUE . "------------------------------");
			$sender->sendMessage(TextFormat::GRAY . "Build " . TextFormat::DARK_GRAY . substr(VersionInfo::GIT_HASH(), 0, 12));
			$sender->sendMessage(TextFormat::DARK_AQUA . "QXRND - PocketMine-MP " . TextFormat::GRAY . "- desarrollado por " . TextFormat::GOLD . "DevPapo");
		}else{
			$pluginName = implode(" ", $args);
			$exactPlugin = $sender->getServer()->getPluginManager()->getPlugin($pluginName);

			if($exactPlugin instanceof Plugin){
				$this->describeToSender($exactPlugin, $sender);

				return true;
			}

			$found = false;
			$pluginName = strtolower($pluginName);
			foreach($sender->getServer()->getPluginManager()->getPlugins() as $plugin){
				if(stripos($plugin->getName(), $pluginName) !== false){
					$this->describeToSender($plugin, $sender);
					$found = true;
				}
			}

			if(!$found){
				$sender->sendMessage(KnownTranslationFactory::pocketmine_command_version_noSuchPlugin());
			}
		}

		return true;
	}

	private function describeToSender(Plugin $plugin, CommandSender $sender) : void{
		$desc = $plugin->getDescription();
		$sender->sendMessage(KnownTranslationFactory::pocketmine_command_version_plugin_header(
			TextFormat::DARK_GREEN . $desc->getName() . TextFormat::RESET,
			TextFormat::DARK_GREEN . $desc->getVersion() . TextFormat::RESET
		));

		if($desc->getDescription() !== ""){
			$sender->sendMessage($desc->getDescription());
		}

		if($desc->getWebsite() !== ""){
			$sender->sendMessage(KnownTranslationFactory::pocketmine_command_version_plugin_website($desc->getWebsite()));
		}

		if(count($authors = $desc->getAuthors()) > 0){
			if(count($authors) === 1){
				$sender->sendMessage(KnownTranslationFactory::pocketmine_command_version_plugin_author(implode(", ", $authors)));
			}else{
				$sender->sendMessage(KnownTranslationFactory::pocketmine_command_version_plugin_authors(implode(", ", $authors)));
			}
		}
	}
}
