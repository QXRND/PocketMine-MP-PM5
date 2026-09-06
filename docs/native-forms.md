# Native Forms API

QXRND - PocketMine-MP PM5 includes a native Bedrock forms API under `pocketmine\form`. It uses the existing `ModalFormRequestPacket` and `ModalFormResponsePacket` transport and does not require an external FormAPI plugin.

## Simple forms

```php
use pocketmine\form\SimpleForm;

$player->sendForm(
    (new SimpleForm('Server menu', 'Choose an option.'))
        ->addButton('Shop', function(Player $player, SimpleForm $form, int $button) : void{
            $player->sendMessage('Shop selected');
        })
        ->addButton('Rules')
);
```

`SimpleForm::addButton()` accepts optional Bedrock image metadata with `path` or `url` as the image type. A form-level handler can be configured with `setHandler()`.

## Modal forms

```php
use pocketmine\form\ModalForm;

$player->sendForm(
    (new ModalForm('Confirm', 'Enable the feature?', 'Enable', 'Cancel'))
        ->setHandler(function(Player $player, bool $accepted) : void{
            if($accepted){
                $player->sendMessage('Enabled');
            }
        })
);
```

## Custom forms

```php
use pocketmine\form\CustomForm;

$form = (new CustomForm('Settings'))
    ->addLabel('Configure your profile')
    ->addInput('Name', 'Your name')
    ->addToggle('Notifications', true)
    ->addDropdown('Rank', ['Member', 'VIP'], 0)
    ->setHandler(function(Player $player, array $values) : void{
        // Values are returned in the same order as the form controls.
    });

$player->sendForm($form);
```

Available controls are `addLabel()`, `addInput()`, `addToggle()`, `addDropdown()`, `addSlider()` and `addStepSlider()`. Responses are validated before the handler is called. Cancelled forms are delivered as `null` and do not invoke the handler.

The API is part of the PM5 core and is available to plugins through the normal Composer autoloader.
