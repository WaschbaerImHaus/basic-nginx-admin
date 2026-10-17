<?php
declare(strict_types=1);

/**
 * Tests für den Catch-all (src/etc/nginx-default.conf, installiert als 00-default.conf).
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-10-18 00:00
 */

namespace Tests\Nginx;

use PHPUnit\Framework\TestCase;

final class DefaultServerTest extends TestCase
{
	private static function config(): string
	{
		return (string)file_get_contents(dirname(__DIR__, 2) . '/src/etc/nginx-default.conf');
	}

	/**
	 * Port 80: unbekannte Hostnamen bekommen keine Antwort (444).
	 */
	public function testPort80AnswersUnknownNamesWithNothing(): void
	{
		$text = self::config();
		self::assertStringContainsString("    listen 80 default_server;\n", $text);
		self::assertStringContainsString("    listen [::]:80 default_server;\n", $text);
		self::assertStringContainsString("    return 444;\n", $text);
	}

	/**
	 * HTTPS und QUIC (Nutzerwunsch 2026-10-17): Ohne eigenen Standard-Server bekäme ein
	 * unbekannter Name (SNI) das Zertifikat und die Antwort des ersten Hosts – das
	 * verriete dessen Namen. ssl_reject_handshake bricht schon den TLS-Handshake ab und
	 * braucht dafür kein Zertifikat.
	 */
	public function testHttpsAndQuicRejectUnknownNamesInTheHandshake(): void
	{
		$text = self::config();
		foreach (['listen 443 ssl default_server;', 'listen [::]:443 ssl default_server;',
			'listen 443 quic default_server;', 'listen [::]:443 quic default_server;',
			'ssl_reject_handshake on;'] as $line) {
			self::assertStringContainsString("    $line\n", $text);
		}
		self::assertStringNotContainsString('ssl_certificate', $text, 'der Catch-all braucht und zeigt kein Zertifikat');
	}
}
