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

namespace pocketmine\console;

use pocketmine\utils\Utils;
use function fclose;
use function fopen;
use function fread;
use function is_resource;
use function strpos;
use function stream_set_blocking;
use function substr;
use function trim;

final class ConsoleReader{
	/** @var resource|null */
	private $stdin;
	private string $buffer = "";

	public function __construct(){
		$this->initStdin();
	}

	private function initStdin() : void{
		if(is_resource($this->stdin)){
			fclose($this->stdin);
		}

		$this->stdin = Utils::assumeNotFalse(fopen("php://stdin", "r"), "Opening stdin should never fail");
		stream_set_blocking($this->stdin, false);
	}

	/**
	 * Reads one complete line without blocking the server tick.
	 *
	 * This works with both an interactive terminal and the pipe used by
	 * Pterodactyl's console. Partial writes are retained until a newline arrives.
	 */
	public function readLine() : ?string{
		if(!is_resource($this->stdin)){
			$this->initStdin();
		}

		while(($chunk = fread($this->stdin, 8192)) !== false && $chunk !== ""){
			$this->buffer .= $chunk;
			if(strpos($chunk, "\n") === false){
				break;
			}
		}

		$lineEnd = strpos($this->buffer, "\n");
		if($lineEnd === false){
			return null;
		}

		$line = trim(substr($this->buffer, 0, $lineEnd));
		$this->buffer = substr($this->buffer, $lineEnd + 1);

		return $line !== "" ? $line : null;
	}

	public function quit() : void{
		if(is_resource($this->stdin)){
			fclose($this->stdin);
			$this->stdin = null;
		}
	}

	public function __destruct(){
		$this->quit();
	}
}
