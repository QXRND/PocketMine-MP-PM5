<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$reader = new pocketmine\console\ConsoleReader();
$deadline = microtime(true) + 1.0;
$lines = [];
while(count($lines) < 2 && microtime(true) < $deadline){
	if(($line = $reader->readLine()) !== null){
		$lines[] = $line;
	}
	usleep(1000);
}
$reader->quit();
if($lines !== ['ver', 'status']){
	throw new RuntimeException('Console reader returned: ' . json_encode($lines, JSON_THROW_ON_ERROR));
}
echo "console reader smoke test passed\n";
