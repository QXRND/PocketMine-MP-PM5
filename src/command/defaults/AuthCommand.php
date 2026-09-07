<?php

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\player\Player;
use pocketmine\Server;

final class AuthCommand extends Command{
	public function __construct(){
		parent::__construct('auth', 'Manage native registration and login accounts', '/auth <change|reset|delete> ...', ['changepassword']);
		$this->setPermission(DefaultPermissionNames::COMMAND_AUTH);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args) : bool{
		if($args === []){
			$sender->sendMessage('Usage: /auth change <old-password> <new-password> | /auth reset <player> <new-password> | /auth delete <player>');
			return true;
		}
		$server = $sender->getServer();
		$auth = $server->getAuthManager();
		$subcommand = strtolower((string) array_shift($args));
		switch($subcommand){
			case 'change':
			case 'changepassword':
				if(!$sender instanceof Player){
					$sender->sendMessage('Only a player can change their own password.');
					return true;
				}
				if(!$auth->isAuthenticated($sender)){
					$sender->sendMessage('You must be authenticated first.');
					return true;
				}
				if(count($args) !== 2 || strlen($args[0]) < 6 || strlen($args[0]) > 128 || strlen($args[1]) < 6 || strlen($args[1]) > 128){
					$sender->sendMessage('Usage: /auth change <old-password> <new-password> (passwords must be 6-128 characters)');
					return true;
				}
				if(!$auth->handlePasswordChange($sender, $args[0], $args[1])){
					$sender->sendMessage('Unable to change password. Check the old password and try again.');
					return true;
				}
				$sender->sendMessage('Password changed successfully.');
				return true;
			case 'reset':
				if(!$this->isAdmin($sender)){
					$sender->sendMessage('You must be an operator to reset another account.');
					return true;
				}
				if(count($args) !== 2 || strlen($args[1]) < 6 || strlen($args[1]) > 128){
					$sender->sendMessage('Usage: /auth reset <player> <new-password>');
					return true;
				}
				$sender->sendMessage($auth->resetPassword($args[0], $args[1]) ? 'Password reset successfully.' : 'Account not found.');
				return true;
			case 'delete':
				if(!$this->isAdmin($sender)){
					$sender->sendMessage('You must be an operator to delete accounts.');
					return true;
				}
				if(count($args) !== 1){
					$sender->sendMessage('Usage: /auth delete <player>');
					return true;
				}
				$sender->sendMessage($auth->deleteAccount($args[0]) ? 'Account deleted successfully.' : 'Account not found.');
				return true;
			default:
				$sender->sendMessage('Unknown subcommand. Use change, reset or delete.');
				return true;
		}
	}

	private function isAdmin(CommandSender $sender) : bool{
		return !$sender instanceof Player || $sender->isOp();
	}
}
