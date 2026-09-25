<?php
declare(strict_types=1);

/**
 * Tests der Netz- und Länderzuordnung aus den RIR-Statistikdateien.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-23 09:00
 */

namespace Tests\Honeypot;

use Honeypot\NetworkRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempDir;

final class NetworkRegistryTest extends TestCase
{
	private string $dir;
	private NetworkRegistry $registry;

	protected function setUp(): void
	{
		$this->dir = TempDir::create();
		// Format der aufbereiteten Tabelle: start end land registry netz
		// (start/end als 32 Hexzeichen, IPv4 als IPv4-mapped IPv6 – dadurch lassen sich
		// beide Familien in einer Tabelle vergleichen.)
		$this->table($this->lines([
			['87.106.0.0', 65536, 'DE', 'ripencc'],
			['8.8.8.0', 256, 'US', 'arin'],
			['1.1.1.0', 256, 'AU', 'apnic'],
		]));
		$this->registry = new NetworkRegistry($this->dir . '/networks.txt');
	}

	protected function tearDown(): void
	{
		TempDir::remove($this->dir);
	}

	/** @param list<array{string, int, string, string}> $blocks */
	private function lines(array $blocks): string
	{
		$out = '';
		foreach ($blocks as [$start, $count, $cc, $registry]) {
			$first = str_pad(bin2hex((string)inet_pton('::ffff:' . $start)), 32, '0', STR_PAD_LEFT);
			$lastIp = long2ip(ip2long($start) + $count - 1);
			$last = str_pad(bin2hex((string)inet_pton('::ffff:' . $lastIp)), 32, '0', STR_PAD_LEFT);
			$bits = 32 - (int)log($count, 2);
			$out .= "$first $last $cc $registry $start/$bits\n";
		}
		return $out;
	}

	private function table(string $content): void
	{
		file_put_contents($this->dir . '/networks.txt', $content);
	}

	public function testFindsTheBlockCountryAndRegistryOfAnAddress(): void
	{
		$info = $this->registry->lookup('87.106.116.167');
		self::assertSame('DE', $info?->country);
		self::assertSame('ripencc', $info?->registry);
		self::assertSame('87.106.0.0/16', $info?->network);
	}

	/**
	 * Die Grenzen gehören zum Block – ein Fehler um eins würde ganze Netze dem
	 * falschen Land zuschlagen.
	 */
	public function testIncludesBothEndsOfTheBlock(): void
	{
		self::assertSame('US', $this->registry->lookup('8.8.8.0')?->country);
		self::assertSame('US', $this->registry->lookup('8.8.8.255')?->country);
		self::assertNull($this->registry->lookup('8.8.9.0'), 'direkt hinter dem Block');
		self::assertNull($this->registry->lookup('8.8.7.255'), 'direkt vor dem Block');
	}

	public function testUnknownAddressGivesNothing(): void
	{
		self::assertNull($this->registry->lookup('203.0.113.7'));
	}

	public function testRejectsSomethingThatIsNotAnAddress(): void
	{
		self::assertNull($this->registry->lookup('keine-ip'));
		self::assertNull($this->registry->lookup(''));
	}

	/**
	 * Nachgeschlagen wird in einem Durchlauf durch die Datei: Die Tabelle hat rund eine
	 * Viertelmillion Zeilen, und je Auswertung stehen nur eine Handvoll Adressen an.
	 */
	public function testLooksUpSeveralAddressesAtOnce(): void
	{
		$found = $this->registry->lookupMany(['87.106.116.167', '1.1.1.1', '203.0.113.7']);
		self::assertSame(['87.106.116.167', '1.1.1.1'], array_keys($found));
		self::assertSame('AU', $found['1.1.1.1']->country);
	}

	public function testWithoutATableNothingIsFoundAndNothingBreaks(): void
	{
		$registry = new NetworkRegistry($this->dir . '/fehlt.txt');
		self::assertNull($registry->lookup('8.8.8.8'));
		self::assertSame([], $registry->lookupMany(['8.8.8.8']));
		self::assertFalse($registry->isAvailable());
	}

	public function testKnowsWhetherItHasATable(): void
	{
		self::assertTrue($this->registry->isAvailable());
	}

	/**
	 * IPv6 muss mit derselben Tabelle funktionieren; der Verkehr hierher ist teilweise
	 * IPv6, und eine Tabelle nur für IPv4 wäre eine halbe Antwort.
	 */
	public function testHandlesIpv6WithTheSameTable(): void
	{
		$first = bin2hex((string)inet_pton('2a02:2e0::'));
		$last = bin2hex((string)inet_pton('2a02:2e0:ffff:ffff:ffff:ffff:ffff:ffff'));
		$this->table("$first $last DE ripencc 2a02:2e0::/32\n");
		$registry = new NetworkRegistry($this->dir . '/networks.txt');
		self::assertSame('DE', $registry->lookup('2a02:2e0::1')?->country);
		self::assertSame('2a02:2e0::/32', $registry->lookup('2a02:2e0::1')?->network);
	}
}
