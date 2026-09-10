<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe;

use PHPUnit\Framework\TestCase;
use pocketmine\network\mcpe\protocol\ProtocolInfo;

final class ProtocolMultiVersionTest extends TestCase{
	/**
	 * @return array<string, array{string, int}>
	 */
	public static function supportedVersionProvider() : array{
		return [
			'1.21.111' => ['1.21.111', ProtocolInfo::PROTOCOL_1_21_111],
			'1.21.114' => ['1.21.114', ProtocolInfo::PROTOCOL_1_21_111],
			'1.26.40' => ['1.26.40', ProtocolInfo::PROTOCOL_1_26_40],
			'1.26.42' => ['1.26.42', ProtocolInfo::PROTOCOL_1_26_40],
			'1.26.44' => ['1.26.44', ProtocolInfo::PROTOCOL_1_26_40],
			'1.26.45' => ['1.26.45', ProtocolInfo::PROTOCOL_1_26_45],
		];
	}

	/**
	 * @dataProvider supportedVersionProvider
	 */
	public function testRequestedVersionUsesAcceptedProtocol(string $version, int $protocol) : void{
		self::assertContains($protocol, ProtocolInfo::ACCEPTED_PROTOCOL, $version . ' protocol is not accepted');
	}

	public function testCurrentProtocolIsLatestRequestedVersion() : void{
		self::assertSame(ProtocolInfo::PROTOCOL_1_26_45, ProtocolInfo::CURRENT_PROTOCOL);
	}
}
