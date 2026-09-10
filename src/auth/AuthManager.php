<?php

declare(strict_types=1);

namespace pocketmine\auth;

use pocketmine\form\CustomForm;
use pocketmine\player\Player;
use pocketmine\Server;
use function array_key_exists;
use function max;
use function strlen;

final class AuthManager{
	private ?AuthDatabase $database = null;
	private int $maxAttempts;
	/** @var array<int, int> */
	private array $failedAttempts = [];
	/** @var array<int, true> */
	private array $authenticated = [];
	/** @var array<int, true> */
	private array $presented = [];

	public function __construct(private Server $server){
		$cfg = $server->getConfigGroup();
		$this->maxAttempts = max(1, $cfg->getPropertyInt('authentication.max-attempts', 3));
		if(!$cfg->getPropertyBool('authentication.enabled', false)){
			return;
		}
		$path = $cfg->getPropertyString('authentication.database', 'accounts.db');
		if($path === '' || $path[0] !== '/'){
			$path = $server->getDataPath() . DIRECTORY_SEPARATOR . $path;
		}
		try{
			$this->database = new AuthDatabase($path);
		}catch(\Throwable $e){
			$server->getLogger()->critical('Native authentication has been disabled because its SQLite database could not be opened: ' . $e->getMessage());
		}
	}

	public function isEnabled() : bool{
		return $this->database !== null && $this->server->getConfigGroup()->getPropertyBool('authentication.enabled', false);
	}

	public function isAuthenticated(Player $player) : bool{
		return !$this->isEnabled() || isset($this->authenticated[$player->getId()]);
	}

	public function begin(Player $player) : void{
		if(!$this->isEnabled() || $this->isAuthenticated($player) || isset($this->presented[$player->getId()])){
			return;
		}
		$id = $player->getId();
		$this->presented[$id] = true;
		$this->failedAttempts[$id] = $this->failedAttempts[$id] ?? 0;
		$player->setNoClientPredictions(true);
		try{
			if($this->database?->hasAccount($player->getName()) ?? false){
				$this->sendLoginForm($player);
			}else{
				$this->sendRegisterForm($player);
			}
		}catch(\Throwable $e){
			$this->failAuthentication($player, $e);
		}
	}

	public function logout(Player $player) : void{
		$id = $player->getId();
		unset($this->authenticated[$id], $this->presented[$id], $this->failedAttempts[$id]);
	}

	public function handlePasswordChange(Player $player, string $oldPassword, string $newPassword) : bool{
		return $this->database?->changePassword($player->getName(), $oldPassword, $newPassword) ?? false;
	}

	public function resetPassword(string $username, string $newPassword) : bool{
		return $this->database?->setPassword($username, $newPassword) ?? false;
	}

	public function deleteAccount(string $username) : bool{
		return $this->database?->deleteAccount($username) ?? false;
	}

	public function hasAccount(string $username) : bool{
		return $this->database?->hasAccount($username) ?? false;
	}

	private function sendRegisterForm(Player $player) : void{
		$form = (new CustomForm('Create your account'))
			->addLabel('Register to continue playing on QXRND - PocketMine-MP.')
			->addInput('Password', 'Enter a password', '')
			->addInput('Confirm password', 'Repeat your password', '')
			->setHandler(function(Player $player, ?array $values) : void{
				if($values === null){
					$this->sendRegisterForm($player);
					return;
				}
				$password = (string) ($values[1] ?? '');
				$confirmation = (string) ($values[2] ?? '');
				if(strlen($password) < 6 || strlen($password) > 128 || $password !== $confirmation){
					$player->sendMessage('§cRegistration failed. Use 6-128 characters and make both passwords identical.');
					$this->sendRegisterForm($player);
					return;
				}
				if(!($this->database?->createAccount($player->getName(), $password) ?? false)){
					$player->sendMessage('§cThis account could not be created. Contact an administrator.');
					$this->sendRegisterForm($player);
					return;
				}
				$this->complete($player);
			});
		$player->sendForm($form);
	}

	private function sendLoginForm(Player $player) : void{
		$form = (new CustomForm('Sign in'))
			->addLabel('Enter your password to continue. Contact an administrator if you forgot it.')
			->addInput('Password', 'Enter your password', '')
			->setHandler(function(Player $player, ?array $values) : void{
				if($values === null){
					$this->sendLoginForm($player);
					return;
				}
				$password = (string) ($values[1] ?? '');
				if(!($this->database?->verifyPassword($player->getName(), $password) ?? false)){
					$id = $player->getId();
					$this->failedAttempts[$id] = ($this->failedAttempts[$id] ?? 0) + 1;
					if($this->failedAttempts[$id] >= $this->maxAttempts){
						$player->kick('Too many incorrect password attempts.');
						return;
					}
					$remaining = $this->maxAttempts - $this->failedAttempts[$id];
					$player->sendMessage("§cIncorrect password. Attempts remaining: $remaining.");
					$this->sendLoginForm($player);
					return;
				}
				$this->complete($player);
			});
		$player->sendForm($form);
	}

	private function failAuthentication(Player $player, \Throwable $e) : void{
		$id = $player->getId();
		unset($this->presented[$id], $this->failedAttempts[$id]);
		$player->setNoClientPredictions(false);
		$this->server->getLogger()->error('Native authentication failed for ' . $player->getName() . ': ' . $e->getMessage());
		$player->kick('Authentication is temporarily unavailable. Please try again later.');
	}

	private function complete(Player $player) : void{
		$id = $player->getId();
		$this->authenticated[$id] = true;
		unset($this->presented[$id], $this->failedAttempts[$id]);
		$player->setNoClientPredictions(false);
		$player->sendMessage('§aAuthentication successful. Welcome.');
	}
}
