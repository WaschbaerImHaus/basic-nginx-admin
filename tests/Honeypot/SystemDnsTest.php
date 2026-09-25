<?php
declare(strict_types=1);

/**
 * Tests der DNS64-Behandlung. Der LXC hat nur IPv6 nach aussen; sein Auflöser erfindet
 * für reine IPv4-Namen AAAA-Einträge im Präfix 64:ff9b::/96. Diese Adressen gehören
 * keiner Registry – die echte steht in den letzten 32 Bit.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-25 10:30
 */

namespace Tests\Honeypot;

use Honeypot\SystemDns;
use PHPUnit\Framework\TestCase;

final class SystemDnsTest extends TestCase
{
	public function testUnwrapsTheIpv4AddressHiddenInADns64Address(): void
	{
		// So löst mfsvr.de hier tatsächlich auf.
		self::assertSame('87.106.116.167', SystemDns::unwrapNat64('64:ff9b::576a:74a7'));
		self::assertSame('87.106.116.167', SystemDns::unwrapNat64('64:ff9b::87.106.116.167'));
	}

	public function testLeavesEveryOtherAddressAlone(): void
	{
		self::assertSame('2a02:2e0::1', SystemDns::unwrapNat64('2a02:2e0::1'));
		self::assertSame('8.8.8.8', SystemDns::unwrapNat64('8.8.8.8'));
		self::assertSame('kein', SystemDns::unwrapNat64('kein'));
	}

	/**
	 * Nur das bekannte Präfix /96 – eine Adresse, die bloss ähnlich anfängt, ist echtes
	 * IPv6 und muss es bleiben.
	 */
	public function testOnlyTheWellKnownPrefixCounts(): void
	{
		self::assertSame('64:ff9b:1::576a:74a7', SystemDns::unwrapNat64('64:ff9b:1::576a:74a7'));
	}
}
