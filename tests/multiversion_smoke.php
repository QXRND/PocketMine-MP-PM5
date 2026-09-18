<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use pocketmine\network\mcpe\protocol\ProtocolInfo;

$expected = [
	'1.21.111' => ProtocolInfo::PROTOCOL_1_21_111,
	'1.21.114' => ProtocolInfo::PROTOCOL_1_21_111,
	'1.26.40' => ProtocolInfo::PROTOCOL_1_26_40,
	'1.26.42' => ProtocolInfo::PROTOCOL_1_26_40,
	'1.26.44' => ProtocolInfo::PROTOCOL_1_26_40,
	'1.26.30' => ProtocolInfo::PROTOCOL_1_26_30,
	'1.26.45' => ProtocolInfo::PROTOCOL_1_26_45,
	'1.26.50' => ProtocolInfo::PROTOCOL_1_26_50,
	'1.26.51' => ProtocolInfo::PROTOCOL_1_26_51,
];

foreach($expected as $version => $protocol){
	if(!in_array($protocol, ProtocolInfo::ACCEPTED_PROTOCOL, true)){
		throw new RuntimeException("{$version} maps to unaccepted protocol {$protocol}");
	}
}

if(ProtocolInfo::CURRENT_PROTOCOL !== ProtocolInfo::PROTOCOL_1_26_51){
	throw new RuntimeException('Current protocol is not 2193');
}

echo "multiversion smoke test passed\n";
