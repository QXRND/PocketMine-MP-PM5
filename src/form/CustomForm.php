<?php

declare(strict_types=1);

namespace pocketmine\form;

use Closure;
use pocketmine\player\Player;

final class CustomForm implements Form{
	/** @var list<array<string, mixed>> */
	private array $content = [];
	private ?Closure $handler = null;

	public function __construct(private string $title = ''){ }

	public function setTitle(string $title) : self{
		$this->title = $title;
		return $this;
	}

	public function addLabel(string $text) : self{
		$this->content[] = ['type' => 'label', 'text' => $text];
		return $this;
	}

	public function addInput(string $text, string $placeholder = '', string $default = '') : self{
		$this->content[] = ['type' => 'input', 'text' => $text, 'placeholder' => $placeholder, 'default' => $default];
		return $this;
	}

	public function addToggle(string $text, bool $default = false) : self{
		$this->content[] = ['type' => 'toggle', 'text' => $text, 'default' => $default];
		return $this;
	}

	/** @param list<string> $options */
	public function addDropdown(string $text, array $options, int $default = 0) : self{
		if($options === [] || $default < 0 || $default >= count($options)){
			throw new \InvalidArgumentException('CustomForm dropdown requires options and a valid default index');
		}
		$this->content[] = ['type' => 'dropdown', 'text' => $text, 'options' => array_values($options), 'default' => $default];
		return $this;
	}

	public function addSlider(string $text, float $min, float $max, float $step = 1.0, float $default = 0.0) : self{
		$this->validateRange($min, $max, $step, $default);
		$this->content[] = ['type' => 'slider', 'text' => $text, 'min' => $min, 'max' => $max, 'step' => $step, 'default' => $default];
		return $this;
	}

	/** @param list<string> $steps */
	public function addStepSlider(string $text, array $steps, int $default = 0) : self{
		if($steps === [] || $default < 0 || $default >= count($steps)){
			throw new \InvalidArgumentException('CustomForm step slider requires steps and a valid default index');
		}
		$this->content[] = ['type' => 'step_slider', 'text' => $text, 'steps' => array_values($steps), 'default' => $default];
		return $this;
	}

	public function setHandler(?Closure $handler) : self{
		$this->handler = $handler;
		return $this;
	}

	public function jsonSerialize() : array{
		return ['type' => 'custom_form', 'title' => $this->title, 'content' => $this->content];
	}

	public function handleResponse(Player $player, $data) : void{
		if($data === null){
			if($this->handler !== null){
				($this->handler)($player, null);
			}
			return;
		}
		if(!is_array($data)){
			throw new FormValidationException('CustomForm response must be an array or null');
		}
		$values = [];
		$index = 0;
		foreach($this->content as $element){
			if($element['type'] === 'label'){
				$values[] = null;
				continue;
			}
			if(!array_key_exists($index, $data)){
				throw new FormValidationException("CustomForm response is missing element $index");
			}
			$value = $data[$index++];
			$this->validateValue($element, $value);
			$values[] = $value;
		}
		if($this->handler !== null){
			($this->handler)($player, $values);
		}
	}

	private function validateRange(float $min, float $max, float $step, float $default) : void{
		if($max <= $min || $step <= 0 || $default < $min || $default > $max){
			throw new \InvalidArgumentException('CustomForm slider range is invalid');
		}
	}

	private function validateValue(array $element, mixed $value) : void{
		switch($element['type']){
			case 'input':
				if(!is_string($value)) throw new FormValidationException('CustomForm input response must be a string');
				break;
			case 'toggle':
				if(!is_bool($value)) throw new FormValidationException('CustomForm toggle response must be boolean');
				break;
			case 'dropdown':
			case 'step_slider':
				if(!is_int($value) || $value < 0 || $value >= count($element[$element['type'] === 'dropdown' ? 'options' : 'steps'])) throw new FormValidationException('CustomForm selection response is invalid');
				break;
			case 'slider':
				if(!is_int($value) && !is_float($value)) throw new FormValidationException('CustomForm slider response must be numeric');
				if($value < $element['min'] || $value > $element['max']) throw new FormValidationException('CustomForm slider response is out of range');
				break;
		}
	}
}
