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
		foreach (['/', '/index.html', '/phpinfo.html', '/a/b/phpinfo.php', '/.env/x', '/environment', '/a/server-info',
			'/.envy', '/x.env', '/phpinfo.php/..', '/admin/', '/wp-config.php/x', '/configuration.php', '/myconfig.php',
			'/server-statusx', '/index.php?file=../etc/passwd', '/index.php?file=.aws/credentials', '/.aws/credentials'] as $path) {
			self::assertNull(Decoys::kindOf($path), $path);
		}
	}

	/**
	 * Neue Köder vom 2026-09-27, nach den gefragten Pfaden: wp-config.php samt
	 * Sicherungskopien, config.php-Varianten, Apaches Status- und Infoseite.
	 */
	public function testRecognisesTheNewDecoys(): void
	{
		$expected = [
			'/wp-config.php' => 'wpconfig', '/wp-config.php.bak' => 'wpconfig', '/wp-config.php~' => 'wpconfig',
			'/blog/wp-config.php.save' => 'wpconfig', '/wp-config.old' => 'wpconfig', '/.wp-config.php.swp' => 'wpconfig',
			'/config.dev.php' => 'config', '/config.php' => 'config', '/app/config.local.php' => 'config',
			'/config.inc.php.bak' => 'config',
			'/server-status' => 'serverstatus', '/server-status/' => 'serverstatus', '/server-status.php' => 'serverstatus',
			'/server-info' => 'serverinfo', '/server-info.php' => 'serverinfo', '/server-status?auto' => 'serverstatus',
		];
		foreach ($expected as $path => $kind) {
			self::assertSame($kind, Decoys::kindOf($path), $path);
		}
	}

	/**
	 * Ein Ausbruch aus dem Verzeichnis über einen Parameter (gefragt: ?file=../…/.aws/credentials).
	 * Verglichen wird die rohe Anfragezeile – nginx prüft dafür $request_uri, nicht den
	 * dekodierten $uri; kodierte Schreibweisen sind deshalb im Muster selbst enthalten.
	 */
	public function testRecognisesPathTraversalToAwsCredentials(): void
	{
		foreach (['/index.php?file=../../../../../../../../root/.aws/credentials', '/?page=..%2F..%2F.aws%2Fcredentials',
			'/view.php?id=1&template=%2e%2e/%2e%2e/home/ubuntu/.aws/credentials'] as $path) {
			self::assertSame('awscreds', Decoys::kindOf($path), $path);
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
		self::assertStringContainsString('map $request_uri $vhostadmin_decoy_query', $http);
		foreach (Decoys::QUERY_PATTERNS as $kind => $pattern) {
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
			// Nach dem Einsetzen muss die Auswertung die Kennung wiederfinden können.
			self::assertContains('0123456789', Decoys::tokensIn(str_replace(Decoys::TOKEN_PLACEHOLDER, '0123456789', $text)), $file);
			// Adressen nur aus den Dokumentationsbereichen (RFC 5737) oder privaten Netzen –
			// keine echte Gegenstelle soll in einem Köder stehen.
			preg_match_all('/\\b(\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}\\.\\d{1,3})\\b/', $text, $m);
			foreach ($m[1] as $ip) {
				self::assertMatchesRegularExpression('/^(192\\.0\\.2|198\\.51\\.100|203\\.0\\.113|127\\.|10\\.|172\\.(1[6-9]|2\\d|3[01])\\.|192\\.168\\.)/', $ip, "$file: $ip");
			}
		}
	}

	/** Die neuen Köder tragen den Servernamen als Platzhalter, nie einen festen Namen. */
	public function testDecoysUseTheHostPlaceholder(): void
	{
		$dir = dirname(__DIR__, 2) . '/honeypot/site/koeder';
		foreach (['wp-config.txt', 'config.txt', 'server-status.html', 'server-info.html', 'aws-credentials.txt'] as $file) {
			self::assertStringContainsString(Decoys::HOST_PLACEHOLDER, (string)file_get_contents($dir . '/' . $file), $file);
		}
	}
}
