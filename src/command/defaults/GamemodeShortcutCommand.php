<?php

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\player\GameMode;
use function count;

final class GamemodeShortcutCommand extends VanillaCommand{
	public function __construct(
		string $name,
		private GameMode $gameMode
	){
		parent::__construct($name, KnownTranslationFactory::pocketmine_command_gamemode_description(), "/{$name} [player]");
		$this->setPermissions([
			DefaultPermissionNames::COMMAND_GAMEMODE_SELF,
			DefaultPermissionNames::COMMAND_GAMEMODE_OTHER
		]);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		$target = $this->fetchPermittedPlayerTarget($sender, $args[0] ?? null, DefaultPermissionNames::COMMAND_GAMEMODE_SELF, DefaultPermissionNames::COMMAND_GAMEMODE_OTHER);
		if($target === null){
			return true;
		}
		if($target->getGamemode() === $this->gameMode){
			$sender->sendMessage(KnownTranslationFactory::pocketmine_command_gamemode_failure($target->getName()));
			return true;
		}
		$target->setGamemode($this->gameMode);
		if($target->getGamemode() !== $this->gameMode){
			$sender->sendMessage(KnownTranslationFactory::pocketmine_command_gamemode_failure($target->getName()));
			return true;
		}
		if($target === $sender){
			Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_gamemode_success_self($this->gameMode->getTranslatableName()));
		}else{
			$target->sendMessage(KnownTranslationFactory::gameMode_changed($this->gameMode->getTranslatableName()));
			Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_gamemode_success_other($this->gameMode->getTranslatableName(), $target->getName()));
		}
		return true;
	}
}
