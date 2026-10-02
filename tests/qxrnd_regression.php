<?php

declare(strict_types=1);

use pocketmine\auth\AuthDatabase;
use pocketmine\block\VanillaBlocks;
use pocketmine\form\CustomForm;
use pocketmine\form\ModalForm;
use pocketmine\form\SimpleForm;
use pocketmine\math\Facing;
use pocketmine\redstone\component\power\PowerComponent;

require dirname(__DIR__) . '/vendor/autoload.php';

function check(bool $condition, string $message) : void{
    if(!$condition){
        throw new RuntimeException($message);
    }
}

$dbPath = tempnam(sys_get_temp_dir(), 'qxrnd-auth-');
check($dbPath !== false, 'Unable to create SQLite temp path');
try{
    $db = new AuthDatabase($dbPath);
    check($db->createAccount('PlayerOne', 'correct-password'), 'Initial account creation failed');
    check(!$db->createAccount('playerone', 'duplicate-password'), 'Case-insensitive duplicate account was accepted');
    check($db->verifyPassword('PLAYERONE', 'correct-password'), 'Correct password was rejected');
    check(!$db->verifyPassword('playerone', 'wrong-password'), 'Wrong password was accepted');
}finally{
    @unlink($dbPath);
    @unlink($dbPath . '-wal');
    @unlink($dbPath . '-shm');
}

$custom = (new CustomForm('Test'))->addDropdown('Choice', ['A', 'B']);
$modal = new ModalForm('Test');
$simple = (new SimpleForm('Test'))->addButton('OK');
check($custom->jsonSerialize()['content'][0]['options'] === ['A', 'B'], 'Custom form options were serialized incorrectly');
check($modal->jsonSerialize()['type'] === 'modal', 'Modal form serialization failed');
check($simple->jsonSerialize()['buttons'][0]['button_text'] === 'OK', 'Simple form serialization failed');

if(class_exists('pmmp\\encoding\\BE')){
    $plate = VanillaBlocks::STONE_PRESSURE_PLATE();
    check((new PowerComponent($plate))->getSignalPower() === 0, 'Unpressed pressure plate emitted redstone power');
    $plate->setPressed(true);
    check((new PowerComponent($plate))->getSignalPower() === 15, 'Pressed pressure plate did not emit full power');

    $piston = VanillaBlocks::PISTON();
    $piston->setFacing(Facing::NORTH);
    check($piston->getFacing() === Facing::NORTH, 'Piston facing setter did not preserve valid direction');
}else{
    echo "Block runtime checks skipped: pmmp\\encoding\\BE is unavailable\n";
}

echo "QXRND regression tests passed\n";
