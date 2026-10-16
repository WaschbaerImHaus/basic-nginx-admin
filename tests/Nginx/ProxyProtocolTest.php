<?php
declare(strict_types=1);

/**
 * Tests für die Einstellung PROXY-Protokoll (Wertobjekt ProxyProtocol).
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-10-16 15:45
 */

namespace Tests\Nginx;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VhostAdmin\Nginx\ProxyProtocol;
use VhostAdmin\Value\Cidr;

final class ProxyProtocolTest extends TestCase
{
	/**
	 * Ohne gespeicherte Einstellung ist das PROXY-Protokoll aus (Standard) und
	 * schreibt nichts in die Konfiguration.
	 */
	public function testDefaultIsOffAndRendersNothing(): void
	{
		$p = ProxyProtocol::fromSettings(null, null, null);
		self::assertFalse($p->enabled);
		self::assertSame([], $p->trusted);
		self::assertSame(8081, $p->httpPort);
		self::assertSame(8444, $p->httpsPort);
		self::assertSame('', $p->listenHttp());
		self::assertSame('', $p->listenHttps());
		self::assertSame('', $p->fastcgiPort());
		self::assertNull($p->httpConfig());
	}

	public function testEnableWithDefaults(): void
	{
		$p = ProxyProtocol::fromSettings(null, null, null)->enable([Cidr::fromString('10.200.0.1')]);
		self::assertTrue($p->enabled);
		self::assertSame("    listen 8081 proxy_protocol;\n    port_in_redirect off;\n", $p->listenHttp());
		self::assertSame("    listen 8444 ssl proxy_protocol;\n    port_in_redirect off;\n", $p->listenHttps());
		self::assertSame("        fastcgi_param SERVER_PORT \$vhostadmin_public_port;\n", $p->fastcgiPort());
	}

	/**
	 * Die http-Konfiguration ersetzt die vom Nutzer geschriebene realip.conf: genau
	 * eine real_ip_header-Anweisung, nur dem Tunnel glauben, dazu die Port-Zuordnung
	 * für PHP.
	 */
	public function testHttpConfig(): void
	{
		$p = ProxyProtocol::fromSettings(null, null, null)
			->enable([Cidr::fromString('10.200.0.1'), Cidr::fromString('192.168.7.0/24')]);
		$expected = ProxyProtocol::HEADER
			. "# PROXY-Protokoll (vhost set proxy_protocol): Nur diesen Absendern wird die\n"
			. "# mitgeschickte Client-Adresse geglaubt; \$remote_addr ist danach die echte Adresse.\n"
			. "set_real_ip_from 10.200.0.1;\n"
			. "set_real_ip_from 192.168.7.0/24;\n"
			. "real_ip_header proxy_protocol;\n"
			. "\n"
			. "# Öffentlicher Port für PHP (SERVER_PORT): Wer über 8081/8444 kommt, hat 80/443 aufgerufen.\n"
			. "map \$server_port \$vhostadmin_public_port {\n"
			. "    8081 80;\n"
			. "    8444 443;\n"
			. "    default \$server_port;\n"
			. "}\n"
			. "\n"
			. "# Catch-all für den Tunnel wie für Port 80: unbekannte Hostnamen bekommen keine Antwort.\n"
			. "server {\n"
			. "    listen 8081 proxy_protocol default_server;\n"
			. "    server_name _;\n"
			. "    return 444;\n"
			. "}\n";
		self::assertSame($expected, $p->httpConfig());
	}

	public function testSettingsRoundTrip(): void
	{
		$p = ProxyProtocol::fromSettings(null, null, null)
			->enable([Cidr::fromString('10.200.0.1'), Cidr::fromString('10.9.0.0/24')], 9081, 9444);
		$s = $p->settings();
		self::assertSame(['proxy_protocol' => 'on', 'proxy_from' => '10.200.0.1,10.9.0.0/24', 'proxy_ports' => '9081,9444'], $s);
		$back = ProxyProtocol::fromSettings($s['proxy_protocol'], $s['proxy_from'], $s['proxy_ports']);
		self::assertEquals($p, $back);
	}

	/**
	 * Ausschalten behält Adressen und Ports – ein späteres "on" ohne Angaben nimmt sie wieder.
	 */
	public function testDisableKeepsAddressesAndPorts(): void
	{
		$p = ProxyProtocol::fromSettings(null, null, null)->enable([Cidr::fromString('10.200.0.1')], 9081, 9444)->disable();
		self::assertFalse($p->enabled);
		self::assertSame(['proxy_protocol' => 'off', 'proxy_from' => '10.200.0.1', 'proxy_ports' => '9081,9444'], $p->settings());
		self::assertSame('', $p->listenHttp());
		self::assertNull($p->httpConfig());

		$again = $p->enable();
		self::assertTrue($again->enabled);
		self::assertSame('10.200.0.1', (string)$again->trusted[0]);
		self::assertSame(9081, $again->httpPort);
	}

	public function testEnableWithoutAnyAddressFails(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Tunneladresse');
		ProxyProtocol::fromSettings(null, null, null)->enable();
	}

	/** @return iterable<string, array{int, int}> */
	public static function invalidPorts(): iterable
	{
		yield 'gleich' => [8081, 8081];
		yield 'HTTP reserviert' => [80, 8444];
		yield 'HTTPS reserviert' => [8081, 443];
		yield 'Oberfläche' => [8080, 8444];
		yield 'zu groß' => [8081, 70000];
		yield 'null' => [0, 8444];
	}

	#[DataProvider('invalidPorts')]
	public function testInvalidPortsAreRejected(int $http, int $https): void
	{
		$this->expectException(\InvalidArgumentException::class);
		ProxyProtocol::fromSettings(null, null, null)->enable([Cidr::fromString('10.200.0.1')], $http, $https, 8080);
	}

	/**
	 * Kaputte gespeicherte Werte (von Hand in der Datenbank geändert) dürfen nicht
	 * zu einer halben Konfiguration führen: dann gilt "aus".
	 */
	public function testBrokenStoredValuesMeanOff(): void
	{
		self::assertFalse(ProxyProtocol::fromSettings('on', 'kein-netz', '8081,8444')->enabled);
		self::assertFalse(ProxyProtocol::fromSettings('on', '10.200.0.1', 'x,y')->enabled);
		self::assertFalse(ProxyProtocol::fromSettings('on', null, null)->enabled);
		self::assertFalse(ProxyProtocol::fromSettings('vielleicht', '10.200.0.1', null)->enabled);
		self::assertTrue(ProxyProtocol::fromSettings('on', '10.200.0.1', null)->enabled);
	}

	public function testUsesPort(): void
	{
		$on = ProxyProtocol::fromSettings(null, null, null)->enable([Cidr::fromString('10.200.0.1')]);
		self::assertTrue($on->usesPort(8081));
		self::assertTrue($on->usesPort(8444));
		self::assertFalse($on->usesPort(3000));
		self::assertFalse($on->disable()->usesPort(8081));
	}

	/**
	 * Nur Dateien mit dem eigenen Kopf gehören vhost-admin – eine von Hand
	 * geschriebene realip.conf bleibt beim Ausschalten stehen.
	 */
	public function testIsGeneratedFile(): void
	{
		self::assertTrue(ProxyProtocol::isGenerated(ProxyProtocol::HEADER . "set_real_ip_from 1.2.3.4;\n"));
		self::assertFalse(ProxyProtocol::isGenerated("# Nur dem vServer glauben\nset_real_ip_from 10.200.0.1;\n"));
		self::assertFalse(ProxyProtocol::isGenerated(''));
	}

	/**
	 * Eigene Ports landen auch in map und Catch-all.
	 */
	public function testHttpConfigWithOwnPorts(): void
	{
		$out = (string)ProxyProtocol::fromSettings(null, null, null)->enable([Cidr::fromString('10.200.0.1')], 9081, 9444)->httpConfig();
		self::assertStringContainsString("    9081 80;\n    9444 443;\n", $out);
		self::assertStringContainsString("listen 9081 proxy_protocol default_server;", $out);
		self::assertStringNotContainsString('8081', $out);
	}

	/**
	 * Die Tunnelports lauschen nur auf IPv4 – eine IPv6-Tunneladresse käme nie an.
	 */
	public function testIpv6TunnelIsRejected(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('IPv4');
		ProxyProtocol::fromSettings(null, null, null)->enable([Cidr::fromString('2001:db8::1')]);
	}

	/**
	 * Ein zu weiter Bereich ließe jeden darin eine Client-Adresse vortäuschen (und damit
	 * IP-Freigaben des Verzeichnisschutzes umgehen).
	 */
	public function testTooWideRangeIsRejected(): void
	{
		foreach (['0.0.0.0/0', '10.0.0.0/8', '192.168.0.0/23'] as $range) {
			try {
				ProxyProtocol::fromSettings(null, null, null)->enable([Cidr::fromString($range)]);
				self::fail("$range angenommen");
			} catch (\InvalidArgumentException $e) {
				self::assertStringContainsString('/24', $e->getMessage());
			}
		}
		self::assertTrue(ProxyProtocol::fromSettings(null, null, null)->enable([Cidr::fromString('192.168.7.0/24')])->enabled);
	}
}
