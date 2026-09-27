<?php
declare(strict_types=1);

/**
 * Tests der Köder (phpinfo, .env) und ihrer nginx-Konfiguration.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-27 10:00
 */

namespace Tests\Honeypot;

use Honeypot\Decoys;
use PHPUnit\Framework\TestCase;
use VhostAdmin\Value\NginxSnippet;
use VhostAdmin\Value\SnippetScope;

final class DecoysTest extends TestCase
{
	/** Die Pfade, die im Log tatsächlich gefragt waren (Stand 2026-09-27). */
	public function testRecognisesTheAskedPhpinfoPaths(): void
	{
		foreach (['/phpinfo.php', '/info.php', '/pinfo.php', '/php-info.php', '/_phpinfo.php', '/old_phpinfo.php',
			'/admin/phpinfo.php', '/wp-admin/phpinfo.php', '/public_html/phpinfo.php', '/PHPINFO.PHP', '/phpinfo.php?x=1'] as $path) {
			self::assertSame('phpinfo', Decoys::kindOf($path), $path);
		}
	}

	public function testRecognisesEnvFiles(): void
	{
		foreach (['/.env', '/.env.bak', '/.env.production', '/app/.env', '/api/.env.local'] as $path) {
			self::assertSame('env', Decoys::kindOf($path), $path);
		}
	}

	/** Alles andere ist kein Köder – auch nichts, was nur ähnlich klingt. */
	public function testIgnoresEverythingElse(): void
	{
		foreach (['/', '/index.html', '/phpinfo.html', '/a/b/phpinfo.php', '/.env/x', '/environment', '/server-info.php',
			'/.envy', '/x.env', '/phpinfo.php/..', '/admin/'] as $path) {
			self::assertNull(Decoys::kindOf($path), $path);
		}
	}

	/** Prozentkodiert gefragt ist dieselbe Datei – nginx dekodiert vor dem Vergleich auch. */
	public function testDecodesThePath(): void
	{
		self::assertSame('env', Decoys::kindOf('/%2Eenv'));
		self::assertSame('phpinfo', Decoys::kindOf('/php%69nfo.php'));
	}

	/** nginx und die Auswertung müssen dieselben Pfade kennen – ein Muster für beide. */
	public function testNginxUsesTheSamePatterns(): void
	{
		$http = Decoys::httpConfig();
		foreach (Decoys::PATTERNS as $kind => $pattern) {
			self::assertStringContainsString('"~*' . $pattern . '" ' . Decoys::FILES[$kind] . ';', $http);
		}
		self::assertStringContainsString('log_format ' . Decoys::LOG_FORMAT . ' escape=json', $http);
		self::assertStringContainsString('$request_id', $http);
	}

	/**
	 * Das Snippet geht über den normalen Weg (`vhost conf`) in den Server-Block und muss
	 * deshalb die Prüfung der eigenen Direktiven bestehen.
	 */
	public function testTheServerSnippetPassesTheSnippetCheck(): void
	{
		$snippet = Decoys::serverSnippet('/var/www/a.de/logs');
		$scope = new SnippetScope('/var/www/a.de', '/var/www/a.de/conf', '/var/www/a.de/logs');
		self::assertSame($snippet, (string)NginxSnippet::fromString($snippet, $scope));
		self::assertStringContainsString('internal;', $snippet);
		self::assertStringContainsString('/var/www/a.de/logs/decoy.log ' . Decoys::LOG_FORMAT, $snippet);
		// Beide Logs: das gewöhnliche für die Statistik, das eigene für die Kennung.
		self::assertStringContainsString('/var/www/a.de/logs/access.log;', $snippet);
	}

	/**
	 * Punktdateien protokollieren: Die allgemeine Regel des Wrappers verweigert sie
	 * ohne Logzeile – auf einem Honigtopf verschwände damit /.git/config und Co.
	 */
	public function testDotFilesAreAnsweredAndLogged(): void
	{
		self::assertMatchesRegularExpression('#location \^~ /\. \{\s*return 404;#', Decoys::serverSnippet('/l'));
	}

	/** Eigene Direktiven des Nutzers bleiben stehen; nur der Köderabschnitt wird ersetzt. */
	public function testMergesIntoAnExistingSnippet(): void
	{
		$block = Decoys::serverSnippet('/l');
		self::assertSame($block, Decoys::merge('', $block));
		$own = "add_header X-Test 1;\n";
		$merged = Decoys::merge($own, $block);
		self::assertStringStartsWith($own, $merged);
		self::assertStringContainsString($block, $merged);
		// Erneut: kein zweiter Abschnitt, sondern der alte ersetzt.
		$again = Decoys::merge($merged . "gzip on;\n", Decoys::serverSnippet('/neu'));
		self::assertSame(1, substr_count($again, Decoys::BEGIN));
		self::assertStringContainsString('/neu/decoy.log', $again);
		self::assertStringNotContainsString('/l/decoy.log', $again);
		self::assertStringContainsString('gzip on;', $again);
		self::assertStringContainsString($own, $again);
	}

	/** Kennungen aus dem Ködertext wiederfinden: genau zehn Hexziffern, frei stehend. */
	public function testFindsTokensInText(): void
	{
		self::assertSame(['0123456789', 'abcdefabcd'], Decoys::tokensIn('GET /api/status?key=0123456789 deploy-abcdefabcd'));
		self::assertSame([], Decoys::tokensIn('0123456789a 12345 zz0123456789'));
	}

	/** Die Kodierung im Ködertext: Platzhalter, die nginx ersetzt. */
	public function testTheDecoyFilesCarryThePlaceholders(): void
	{
		$dir = dirname(__DIR__, 2) . '/honeypot/site/koeder';
		foreach (Decoys::FILES as $file) {
			$text = (string)file_get_contents($dir . '/' . $file);
			self::assertStringContainsString(Decoys::TOKEN_PLACEHOLDER, $text, $file);
			// Nichts Echtes: weder dieser Rechner noch reale Pfade der Installation.
			self::assertStringNotContainsString('pServiceWebserver', $text, $file);
			self::assertStringNotContainsString('/var/www/mfsvr.de', $text, $file);
			self::assertStringNotContainsString('vhost-admin', $text, $file);
		}
	}
}
