<?php

declare(strict_types=1);

namespace pocketmine\form;

use pocketmine\player\Player;
use Closure;

final class SimpleForm implements Form{
	/** @var list<array{button_text: string, image?: array{type: string, data: string}}> */
	private array $buttons = [];
	private ?Closure $handler = null;

	public function __construct(private string $title = '', private string $content = ''){ }

	public function setTitle(string $title) : self{
		$this->title = $title;
		return $this;
	}

	public function setContent(string $content) : self{
		$this->content = $content;
		return $this;
	}

	public function addButton(string $text, ?Closure $onClick = null, ?string $imageType = null, ?string $imageData = null) : self{
		$button = ['button_text' => $text];
		if($imageType !== null && $imageData !== null){
			if(!in_array($imageType, ['path', 'url'], true)){
				throw new \InvalidArgumentException('SimpleForm image type must be path or url');
			}
			$button['image'] = ['type' => $imageType, 'data' => $imageData];
		}
		$this->buttons[] = $button;
		if($onClick !== null){
			$this->buttonHandlers[] = $onClick;
		}else{
			$this->buttonHandlers[] = null;
		}
		return $this;
	}

	/** @var list<Closure|null> */
	private array $buttonHandlers = [];

	public function setHandler(?Closure $handler) : self{
		$this->handler = $handler;
		return $this;
	}

	public function jsonSerialize() : array{
		return [
			'type' => 'form',
			'title' => $this->title,
			'content' => $this->content,
			'buttons' => $this->buttons,
		];
	}

	public function handleResponse(Player $player, $data) : void{
		if($data === null){
			return;
		}
		if(!is_int($data) && !(is_string($data) && ctype_digit($data))){
			throw new FormValidationException('SimpleForm response must be a button index or null');
		}
		$index = (int) $data;
		if(!isset($this->buttons[$index])){
			throw new FormValidationException("SimpleForm button index $index is out of range");
		}
		if(isset($this->buttonHandlers[$index]) && $this->buttonHandlers[$index] !== null){
			($this->buttonHandlers[$index])($player, $this, $index);
		}
		if($this->handler !== null){
			($this->handler)($player, $index);
		}
	}
}
