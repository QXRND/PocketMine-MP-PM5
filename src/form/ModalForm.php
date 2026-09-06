<?php

declare(strict_types=1);

namespace pocketmine\form;

use Closure;
use pocketmine\player\Player;

final class ModalForm implements Form{
	private ?Closure $handler = null;

	public function __construct(private string $title = '', private string $content = '', private string $button1 = 'Yes', private string $button2 = 'No'){ }

	public function setTitle(string $title) : self{
		$this->title = $title;
		return $this;
	}

	public function setContent(string $content) : self{
		$this->content = $content;
		return $this;
	}

	public function setButtons(string $button1, string $button2) : self{
		$this->button1 = $button1;
		$this->button2 = $button2;
		return $this;
	}

	public function setHandler(?Closure $handler) : self{
		$this->handler = $handler;
		return $this;
	}

	public function jsonSerialize() : array{
		return [
			'type' => 'modal',
			'title' => $this->title,
			'content' => $this->content,
			'button1' => $this->button1,
			'button2' => $this->button2,
		];
	}

	public function handleResponse(Player $player, $data) : void{
		if($data === null){
			return;
		}
		if(!is_bool($data)){
			throw new FormValidationException('ModalForm response must be boolean or null');
		}
		if($this->handler !== null){
			($this->handler)($player, $data);
		}
	}
}
