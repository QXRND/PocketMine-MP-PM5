<?php

declare(strict_types=1);

namespace pocketmine\auth;

use PDO;
use PDOException;
use function password_hash;
use function password_verify;
use const PASSWORD_DEFAULT;

final class AuthDatabase{
	private PDO $pdo;

	public function __construct(string $path){
		$this->pdo = new PDO('sqlite:' . $path, null, null, [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);
		$this->pdo->exec('PRAGMA journal_mode=WAL');
		$this->pdo->exec('PRAGMA busy_timeout=5000');
		$this->pdo->exec('CREATE TABLE IF NOT EXISTS accounts (username TEXT PRIMARY KEY COLLATE NOCASE, password_hash TEXT NOT NULL, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL)');
	}

	public function hasAccount(string $username) : bool{
		$stmt = $this->pdo->prepare('SELECT 1 FROM accounts WHERE username = :username LIMIT 1');
		$stmt->execute(['username' => $this->normalize($username)]);
		return $stmt->fetchColumn() !== false;
	}

	public function createAccount(string $username, string $password) : bool{
		$now = time();
		$stmt = $this->pdo->prepare('INSERT INTO accounts (username, password_hash, created_at, updated_at) VALUES (:username, :hash, :created, :updated)');
		try{
			return $stmt->execute([
				'username' => $this->normalize($username),
				'hash' => password_hash($password, PASSWORD_DEFAULT),
				'created' => $now,
				'updated' => $now,
			]);
		}catch(PDOException $e){
			if((int) $e->errorInfo[1] === 19){
				return false;
			}
			throw $e;
		}
	}

	public function verifyPassword(string $username, string $password) : bool{
		$stmt = $this->pdo->prepare('SELECT password_hash FROM accounts WHERE username = :username LIMIT 1');
		$stmt->execute(['username' => $this->normalize($username)]);
		$hash = $stmt->fetchColumn();
		return is_string($hash) && password_verify($password, $hash);
	}

	public function changePassword(string $username, string $oldPassword, string $newPassword) : bool{
		if(!$this->verifyPassword($username, $oldPassword)){
			return false;
		}
		return $this->setPassword($username, $newPassword);
	}

	public function setPassword(string $username, string $newPassword) : bool{
		$stmt = $this->pdo->prepare('UPDATE accounts SET password_hash = :hash, updated_at = :updated WHERE username = :username');
		$stmt->execute([
			'hash' => password_hash($newPassword, PASSWORD_DEFAULT),
			'updated' => time(),
			'username' => $this->normalize($username),
		]);
		return $stmt->rowCount() > 0;
	}

	public function deleteAccount(string $username) : bool{
		$stmt = $this->pdo->prepare('DELETE FROM accounts WHERE username = :username');
		$stmt->execute(['username' => $this->normalize($username)]);
		return $stmt->rowCount() > 0;
	}

	public function normalize(string $username) : string{
		// Bedrock usernames are ASCII-compatible; avoid requiring the optional mbstring extension.
		return strtolower(trim($username));
	}
}
