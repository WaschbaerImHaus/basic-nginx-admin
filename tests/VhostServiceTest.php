<?php
declare(strict_types=1);

/**
 * Tests der Anwendungsfälle mit Temp-Verzeichnissen und Fakes.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-20 14:52
 */

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tests\Support\FakeCertbot;
use Tests\Support\FakeFpmReloader;
use Tests\Support\FakeReachability;
use Tests\Support\FakeSystemUsers;
use Tests\Support\FakeReloader;
use Tests\Support\TempDir;
use VhostAdmin\Config;
use VhostAdmin\Database;
use VhostAdmin\Nginx\ConfigRenderer;
use VhostAdmin\Php\PoolRenderer;
use VhostAdmin\Value\Cidr;
use VhostAdmin\Ssl\ReachabilityStatus;
use VhostAdmin\Value\DomainName;
use VhostAdmin\Value\NginxSnippet;
use VhostAdmin\Value\Port;
use VhostAdmin\Value\SubDirectory;
use VhostAdmin\Value\Username;
use VhostAdmin\Ssl\ReachabilityChecker;
use VhostAdmin\Vhost;
use VhostAdmin\VhostKind;
use VhostAdmin\VhostLayout;
use VhostAdmin\VhostRepository;
use VhostAdmin\VhostService;

final class VhostServiceTest extends TestCase
{
	private string $dir;
	private Config $config;
	private VhostRepository $repo;
	private FakeReloader $reloader;
	private FakeFpmReloader $fpmReloader;
	private FakeSystemUsers $systemUsers;
	private FakeReachability $reachability;
	private FakeCertbot $certbot;
	private VhostLayout $layout;
	private VhostService $service;

	protected function setUp(): void
	{
		$this->dir = TempDir::create();
		mkdir($this->dir . '/avail');
		mkdir($this->dir . '/enabled');
		mkdir($this->dir . '/pool.d');
		mkdir($this->dir . '/run');
		$this->config = Config::fromArray([
			'dbPath' => $this->dir . '/db.sqlite',
			'wwwRoot' => $this->dir . '/www',
			'wwwOwner' => 'user',
			'sitesAvailable' => $this->dir . '/avail',
			'sitesEnabled' => $this->dir . '/enabled',
			'authDir' => $this->dir . '/auth',
			'letsEncryptLive' => $this->dir . '/le',
			'ipv6' => false,
			// Pool und Socket ins Temporärverzeichnis, damit die Tests nicht ins echte
			// /etc/php schreiben und ohne root laufen.
			'fpmPoolDir' => $this->dir . '/pool.d',
			'fpmSocketDir' => $this->dir . '/run',
			'backupDir' => $this->dir . '/backups',
		]);
		$db = new Database($this->config);
		$db->initSchema();
		$this->repo = new VhostRepository($db);
		$this->reloader = new FakeReloader();
		$this->fpmReloader = new FakeFpmReloader();
		$this->systemUsers = new FakeSystemUsers();
		$this->reachability = new FakeReachability();
		$this->certbot = new FakeCertbot($this->dir . '/le');
		$this->layout = new VhostLayout($this->config);
		$this->service = new VhostService(
			$this->config, $this->repo, new ConfigRenderer($this->config, $this->layout),
			$this->reloader, $this->certbot, $this->layout,
			new PoolRenderer($this->layout), $this->fpmReloader, $this->systemUsers,
			new ReachabilityChecker($this->reachability)
		);
	}

	protected function tearDown(): void
	{
		TempDir::remove($this->dir);
	}

	public function testCreateDomainCreatesFullLayout(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('example.com'), SubDirectory::fromString('public/html'));
		$base = $this->dir . '/www/example.com';
		self::assertDirectoryExists($base . '/web/public/html');
		self::assertDirectoryExists($base . '/conf');
		self::assertDirectoryExists($base . '/cert');
		self::assertDirectoryExists($base . '/private');
		self::assertDirectoryExists($base . '/logs');
		self::assertFileExists($base . '/web/public/html/index.html');
		self::assertStringContainsString('<h1>200</h1>', (string)file_get_contents($base . '/web/public/html/index.html'));
		self::assertStringContainsString('example.com', (string)file_get_contents($base . '/web/public/html/index.html'));
		self::assertSame(1, $this->reloader->calls);
	}

	public function testCreateAppliesModesFromLayout(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('example.com'), null);
		$expected = [];
		foreach ($this->layout->directories($v) as $spec) {
			$expected[$spec->path] = $spec->mode;
		}
		foreach ($expected as $path => $mode) {
			self::assertDirectoryExists($path);
			// Ohne root werden Besitzer und Gruppe nicht gesetzt, die Rechte aber schon.
			self::assertSame(
				sprintf('%04o', $mode & 07777),
				sprintf('%04o', (fileperms($path) ?: 0) & 07777),
				"Modus von $path"
			);
		}
	}

	public function testLocalhostAlsoGetsLayout(): void
	{
		$v = $this->service->createLocal(Port::fromString('3000'), null, false);
		$base = $this->dir . '/www/localhost-3000';
		foreach (['web', 'conf', 'cert', 'private', 'logs'] as $sub) {
			self::assertDirectoryExists($base . '/' . $sub);
		}
		self::assertStringContainsString('root ' . $base . '/web;', (string)file_get_contents($this->dir . '/avail/localhost-3000.conf'));
	}

	public function testEnableSslCreatesCertificateSymlinks(): void
	{
		$this->service->setLetsEncryptEmail('admin@example.com');
		$v = $this->service->createDomain(DomainName::fromString('example.com'), null);
		$this->service->enableSsl($v);
		$certDir = $this->dir . '/www/example.com/cert';
		self::assertTrue(is_link($certDir . '/fullchain.pem'));
		self::assertTrue(is_link($certDir . '/privkey.pem'));
		self::assertSame($this->dir . '/le/example.com/fullchain.pem', readlink($certDir . '/fullchain.pem'));
	}

	public function testApplyPermissionsRepairsModes(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('example.com'), null);
		chmod($this->dir . '/www/example.com/conf', 0777);
		$this->service->applyPermissions($v);
		self::assertSame('0750', sprintf('%04o', (fileperms($this->dir . '/www/example.com/conf') ?: 0) & 07777));
	}

	public function testCreateLocalBindsLoopback(): void
	{
		$v = $this->service->createLocal(Port::fromString('3000'), null, false);
		self::assertSame('localhost:3000', $v->name);
		self::assertFalse($v->protect);
		self::assertDirectoryExists($this->dir . '/www/localhost-3000');
		$conf = (string)file_get_contents($this->dir . '/avail/localhost-3000.conf');
		self::assertStringContainsString('listen 127.0.0.1:3000;', $conf);
		self::assertStringContainsString('Verzeichnisschutz deaktiviert', (string)file_get_contents($this->dir . '/auth/localhost-3000.conf'));
	}

	public function testCreateKeepsExistingIndex(): void
	{
		mkdir($this->dir . '/www/example.com/web', 0777, true);
		file_put_contents($this->dir . '/www/example.com/web/index.html', 'eigene Seite');
		$this->service->createDomain(DomainName::fromString('example.com'), null);
		self::assertSame('eigene Seite', file_get_contents($this->dir . '/www/example.com/web/index.html'));
	}

	public function testCreateDuplicateThrowsWithoutReload(): void
	{
		$this->service->createDomain(DomainName::fromString('example.com'), null);
		$this->expectException(\RuntimeException::class);
		try {
			$this->service->createDomain(DomainName::fromString('example.com'), null);
		} finally {
			self::assertSame(1, $this->reloader->calls);
		}
	}

	public function testLoadUnknownThrows(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Unbekannter vHost');
		$this->service->load('nix.example');
	}

	public function testProtectionToggleRewritesSnippet(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('example.com'), null);
		$this->service->setProtection($v, false);
		self::assertStringNotContainsString('deny all', (string)file_get_contents($this->dir . '/auth/example.com.conf'));
		self::assertFalse($this->service->load('example.com')->protect);
		$this->service->setProtection($this->service->load('example.com'), true);
		self::assertStringContainsString('deny all', (string)file_get_contents($this->dir . '/auth/example.com.conf'));
		self::assertSame(3, $this->reloader->calls);
	}

	public function testUsersAndIpsEndUpInFiles(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('example.com'), null);
		$this->service->addUser($v, Username::fromString('alice'), 'geheim');
		$this->service->addIp($v, Cidr::fromString('10.0.0.0/8'));
		$htpasswd = (string)file_get_contents($this->dir . '/auth/example.com.htpasswd');
		self::assertMatchesRegularExpression('/^alice:\$6\$[^\n]+\n$/', $htpasswd);
		$hash = trim(substr($htpasswd, strlen('alice:')));
		self::assertSame($hash, crypt('geheim', $hash));
		self::assertNotSame($hash, crypt('falsch', $hash));
		self::assertStringContainsString("allow 10.0.0.0/8;\n", (string)file_get_contents($this->dir . '/auth/example.com.conf'));
		$this->service->removeUser($v, Username::fromString('alice'));
		$this->service->removeIp($v, Cidr::fromString('10.0.0.0/8'));
		self::assertSame('', file_get_contents($this->dir . '/auth/example.com.htpasswd'));
		self::assertStringNotContainsString('allow ', (string)file_get_contents($this->dir . '/auth/example.com.conf'));
	}

	public function testEmptyPasswordIsRejected(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('example.com'), null);
		$this->expectException(\RuntimeException::class);
		$this->service->addUser($v, Username::fromString('alice'), '');
	}

	public function testRemovingUnknownUserOrIpThrows(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('example.com'), null);
		try {
			$this->service->removeUser($v, Username::fromString('nobody'));
			self::fail('Exception erwartet');
		} catch (\RuntimeException $e) {
			self::assertStringContainsString('nobody', $e->getMessage());
		}
		$this->expectException(\RuntimeException::class);
		$this->service->removeIp($v, Cidr::fromString('192.0.2.1'));
	}

	public function testSslNeedsEmail(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('example.com'), null);
		try {
			$this->service->enableSsl($v);
			self::fail('Exception erwartet');
		} catch (\RuntimeException $e) {
			self::assertStringContainsString('E-Mail', $e->getMessage());
		}
		self::assertFalse($this->service->load('example.com')->ssl);
		self::assertSame([], $this->certbot->calls);
	}

	public function testSslRejectsLocalhost(): void
	{
		$this->service->setLetsEncryptEmail('admin@example.com');
		$v = $this->service->createLocal(Port::fromString('3000'), null);
		$this->expectException(\RuntimeException::class);
		$this->service->enableSsl($v);
	}

	public function testSslSuccessWritesHttpsConfig(): void
	{
		$this->service->setLetsEncryptEmail('admin@example.com');
		$v = $this->service->createDomain(DomainName::fromString('example.com'), null);
		$output = $this->service->enableSsl($v);
		self::assertStringContainsString('Simuliertes Zertifikat', $output);
		self::assertTrue($this->service->load('example.com')->ssl);
		$conf = (string)file_get_contents($this->dir . '/avail/example.com.conf');
		self::assertStringContainsString('listen 443 ssl;', $conf);
		self::assertStringContainsString('return 301 https://', $conf);
		$this->service->disableSsl($this->service->load('example.com'));
		self::assertFalse($this->service->load('example.com')->ssl);
		self::assertStringNotContainsString('443', (string)file_get_contents($this->dir . '/avail/example.com.conf'));
	}

	/**
	 * C3 (Abschlussreview): certbot muss web/ als Webroot bekommen, nicht den
	 * Basisordner. Der Renderer bedient die ACME-Location mit "root <basis>/web"
	 * (ConfigRenderer::serverConfig(), Location "^~ /.well-known/acme-challenge/");
	 * bekommt certbot stattdessen den Basisordner, legt es die Challenge-Datei unter
	 * "<basis>/.well-known/..." ab, während nginx sie unter "<basis>/web/.well-known/..."
	 * erwartet – die Ausstellung schlägt fehl, obwohl der Aufruf selbst durchläuft.
	 */
	public function testEnableSslPassesWebDirAsCertbotWebrootToMatchAcmeLocation(): void
	{
		$this->service->setLetsEncryptEmail('admin@example.com');
		$v = $this->service->createDomain(DomainName::fromString('example.com'), null);
		$this->service->enableSsl($v);
		self::assertSame(
			[['example.com', $this->dir . '/www/example.com/web', 'admin@example.com']],
			$this->certbot->calls
		);
	}

	public function testSslSkipsCertbotWhenCertificateExists(): void
	{
		$this->service->setLetsEncryptEmail('admin@example.com');
		$v = $this->service->createDomain(DomainName::fromString('example.com'), null);
		mkdir($this->dir . '/le/example.com', 0700, true);
		file_put_contents($this->dir . '/le/example.com/fullchain.pem', 'x');
		self::assertSame('', $this->service->enableSsl($v));
		self::assertSame([], $this->certbot->calls);
		self::assertTrue($this->service->load('example.com')->ssl);
	}

	public function testSslFailureLeavesSslOff(): void
	{
		$this->service->setLetsEncryptEmail('admin@example.com');
		$v = $this->service->createDomain(DomainName::fromString('example.com'), null);
		$this->certbot->succeed = false;
		try {
			$this->service->enableSsl($v);
			self::fail('Exception erwartet');
		} catch (\RuntimeException) {
		}
		self::assertFalse($this->service->load('example.com')->ssl);
		self::assertStringNotContainsString('443', (string)file_get_contents($this->dir . '/avail/example.com.conf'));
	}

	public function testLetsEncryptEmailValidation(): void
	{
		self::assertNull($this->service->letsEncryptEmail());
		$this->service->setLetsEncryptEmail('admin@example.com');
		self::assertSame('admin@example.com', $this->service->letsEncryptEmail());
		$this->service->setLetsEncryptEmail('');
		self::assertNull($this->service->letsEncryptEmail());
		$this->expectException(\InvalidArgumentException::class);
		$this->service->setLetsEncryptEmail('keine-adresse');
	}

	public function testRemoveKeepsFilesUnlessPurged(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('example.com'), null);
		$this->service->remove($v);
		self::assertNull($this->repo->byName('example.com'));
		self::assertFileDoesNotExist($this->dir . '/avail/example.com.conf');
		self::assertFalse(is_link($this->dir . '/enabled/example.com.conf'));
		self::assertFileDoesNotExist($this->dir . '/auth/example.com.conf');
		self::assertFileDoesNotExist($this->dir . '/auth/example.com.htpasswd');
		self::assertDirectoryExists($this->dir . '/www/example.com');

		$w = $this->service->createDomain(DomainName::fromString('purge.example'), SubDirectory::fromString('public'));
		$this->service->remove($w, true);
		self::assertDirectoryDoesNotExist($this->dir . '/www/purge.example');
		self::assertDirectoryExists($this->dir . '/www');
	}

	public function testRenderAllReloadsOnce(): void
	{
		$this->service->createDomain(DomainName::fromString('a.example'), null);
		$this->service->createLocal(Port::fromString('3001'), null);
		unlink($this->dir . '/avail/a.example.conf');
		$before = $this->reloader->calls;
		$this->service->renderAll();
		self::assertFileExists($this->dir . '/avail/a.example.conf');
		self::assertSame($before + 1, $this->reloader->calls);
	}

	public function testReloadFailurePropagates(): void
	{
		$this->reloader->failWith = 'nginx -t fehlgeschlagen';
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('nginx -t');
		$this->service->createDomain(DomainName::fromString('example.com'), null);
	}

	public function testRenderFailureRestoresPreviousServerConfig(): void
	{
		$this->service->setLetsEncryptEmail('admin@example.com');
		$v = $this->service->createDomain(DomainName::fromString('example.com'), null);
		$this->service->enableSsl($v);
		$before = (string)file_get_contents($this->dir . '/avail/example.com.conf');
		self::assertStringContainsString('listen 443 ssl;', $before);
		$beforeAuthSnippet = (string)file_get_contents($this->dir . '/auth/example.com.conf');
		$beforeHtpasswd = (string)file_get_contents($this->dir . '/auth/example.com.htpasswd');

		$this->reloader->failWith = 'nginx -t fehlgeschlagen';
		try {
			$this->service->disableSsl($this->service->load('example.com'));
			self::fail('Exception erwartet');
		} catch (\RuntimeException) {
		}

		self::assertSame($before, (string)file_get_contents($this->dir . '/avail/example.com.conf'));
		self::assertSame($beforeAuthSnippet, (string)file_get_contents($this->dir . '/auth/example.com.conf'));
		self::assertSame($beforeHtpasswd, (string)file_get_contents($this->dir . '/auth/example.com.htpasswd'));
		self::assertTrue(is_link($this->dir . '/enabled/example.com.conf'));
	}

	public function testRenderFailureRemovesNewlyCreatedFilesAndSymlink(): void
	{
		$this->reloader->failWith = 'nginx -t fehlgeschlagen';
		try {
			$this->service->createDomain(DomainName::fromString('example.com'), null);
			self::fail('Exception erwartet');
		} catch (\RuntimeException) {
		}
		self::assertFileDoesNotExist($this->dir . '/avail/example.com.conf');
		self::assertFileDoesNotExist($this->dir . '/auth/example.com.conf');
		self::assertFalse(is_link($this->dir . '/enabled/example.com.conf'));
	}

	/**
	 * Ruft die private Methode VhostService::purgeBaseDir() per Reflection auf,
	 * damit die Eindämmungslogik selbst geprüft werden kann und nicht nur ihre
	 * Vorbedingungen.
	 */
	private function invokePurgeBaseDir(Vhost $vhost): void
	{
		$method = new \ReflectionMethod(VhostService::class, 'purgeBaseDir');
		$method->invoke($this->service, $vhost);
	}

	/**
	 * Befund 1 (Regression): ist der Basisordner selbst ein Symlink, das auf ein
	 * Verzeichnis außerhalb der Web-Wurzel zeigt, darf "--purge" dessen Ziel nicht
	 * löschen – vorher hätte "rm -rf" auf den unaufgelösten Pfad nur den Symlink
	 * entfernt.
	 */
	public function testPurgeBaseDirRefusesSymlinkPointingOutsideWwwRoot(): void
	{
		mkdir($this->dir . '/outside', 0770, true);
		file_put_contents($this->dir . '/outside/geheim.txt', 'bleibt');
		mkdir($this->dir . '/www', 0775, true);
		symlink($this->dir . '/outside', $this->dir . '/www/symlink.example');
		$vhost = new Vhost(1, 'symlink.example', VhostKind::Domain, null, null, true, false);

		$this->expectException(\RuntimeException::class);
		try {
			$this->invokePurgeBaseDir($vhost);
		} finally {
			self::assertFileExists($this->dir . '/outside/geheim.txt');
		}
	}

	/**
	 * Befund 1 (Regression): zeigt der Symlink auf ein Verzeichnis innerhalb der
	 * Web-Wurzel (z.B. den Docroot eines anderen vHosts), darf dessen Inhalt ebenfalls
	 * nicht gelöscht werden.
	 */
	public function testPurgeBaseDirRefusesSymlinkPointingInsideWwwRoot(): void
	{
		mkdir($this->dir . '/www/other.example', 0775, true);
		file_put_contents($this->dir . '/www/other.example/index.html', 'inhalt');
		symlink($this->dir . '/www/other.example', $this->dir . '/www/symlink.example');
		$vhost = new Vhost(1, 'symlink.example', VhostKind::Domain, null, null, true, false);

		$this->expectException(\RuntimeException::class);
		try {
			$this->invokePurgeBaseDir($vhost);
		} finally {
			self::assertDirectoryExists($this->dir . '/www/other.example');
			self::assertFileExists($this->dir . '/www/other.example/index.html');
			self::assertTrue(is_link($this->dir . '/www/symlink.example'));
		}
	}

	/**
	 * Befund 1 (Regression): zeigt der Symlink auf die Web-Wurzel selbst, besteht der
	 * Präfixvergleich auf dem aufgelösten Pfad ("$realBase === $realRoot") ebenfalls –
	 * ohne Gleichheitsprüfung würde "/var/www" komplett gelöscht.
	 */
	public function testPurgeBaseDirRefusesSymlinkPointingToWwwRootItself(): void
	{
		mkdir($this->dir . '/www', 0775, true);
		file_put_contents($this->dir . '/www/marker.txt', 'bleibt');
		symlink($this->dir . '/www', $this->dir . '/www/symlink.example');
		$vhost = new Vhost(1, 'symlink.example', VhostKind::Domain, null, null, true, false);

		$this->expectException(\RuntimeException::class);
		try {
			$this->invokePurgeBaseDir($vhost);
		} finally {
			self::assertDirectoryExists($this->dir . '/www');
			self::assertFileExists($this->dir . '/www/marker.txt');
		}
	}

	/**
	 * Normalfall: ein echtes Verzeichnis unterhalb der Web-Wurzel wird gelöscht, die
	 * Web-Wurzel und eine Nachbardatei bleiben erhalten.
	 */
	public function testPurgeBaseDirDeletesRealDirectoryUnderWwwRoot(): void
	{
		mkdir($this->dir . '/www/purge.example', 0775, true);
		file_put_contents($this->dir . '/www/purge.example/index.html', 'inhalt');
		file_put_contents($this->dir . '/www/nachbar.txt', 'bleibt');
		$vhost = new Vhost(1, 'purge.example', VhostKind::Domain, null, null, true, false);

		$this->invokePurgeBaseDir($vhost);

		self::assertDirectoryDoesNotExist($this->dir . '/www/purge.example');
		self::assertDirectoryExists($this->dir . '/www');
		self::assertFileExists($this->dir . '/www/nachbar.txt');
	}

	/**
	 * Nicht vorhandener Pfad: kein Fehler, nichts gelöscht.
	 */
	public function testPurgeBaseDirDoesNothingForMissingPath(): void
	{
		mkdir($this->dir . '/www', 0775, true);
		$vhost = new Vhost(1, 'fehlt.example', VhostKind::Domain, null, null, true, false);

		$this->invokePurgeBaseDir($vhost);

		self::assertDirectoryDoesNotExist($this->dir . '/www/fehlt.example');
		self::assertDirectoryExists($this->dir . '/www');
	}

	/**
	 * Snippet setzen schreibt die Datei mit den Soll-Rechten und löst genau einen Reload aus.
	 */
	public function testSetSnippetWritesFileAndReloads(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('example.com'), null);
		$before = $this->reloader->calls;
		$this->service->setSnippet($v, NginxSnippet::fromString("expires 1d;\n"));
		$file = $this->dir . '/www/example.com/conf/custom.conf';
		self::assertFileExists($file);
		self::assertSame("expires 1d;\n", file_get_contents($file));
		self::assertSame("expires 1d;\n", $this->service->snippet($v));
		self::assertSame($before + 1, $this->reloader->calls);
		self::assertSame('0640', sprintf('%04o', (fileperms($file) ?: 0) & 07777));
	}

	/**
	 * Erneutes Setzen ersetzt den Inhalt; ein leeres Snippet löscht die Datei wieder.
	 */
	public function testSetSnippetReplacesAndEmptiesFile(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('example.com'), null);
		$file = $this->dir . '/www/example.com/conf/custom.conf';
		$this->service->setSnippet($v, NginxSnippet::fromString("expires 1d;\n"));
		$this->service->setSnippet($v, NginxSnippet::fromString("autoindex on;\n"));
		self::assertSame("autoindex on;\n", file_get_contents($file));
		$this->service->setSnippet($v, NginxSnippet::fromString(''));
		self::assertFileDoesNotExist($file);
		self::assertSame('', $this->service->snippet($v));
	}

	/**
	 * Scheitert der Reload, muss der vorherige Dateiinhalt zurückgesetzt werden.
	 */
	public function testSetSnippetRestoresPreviousContentWhenReloadFails(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('example.com'), null);
		$file = $this->dir . '/www/example.com/conf/custom.conf';
		$this->service->setSnippet($v, NginxSnippet::fromString("expires 1d;\n"));
		$this->reloader->failWith = 'nginx -t fehlgeschlagen: kaputt';
		try {
			$this->service->setSnippet($v, NginxSnippet::fromString("autoindex on;\n"));
			self::fail('Ausnahme erwartet');
		} catch (\RuntimeException $e) {
			self::assertStringContainsString('nginx -t', $e->getMessage());
		}
		self::assertSame("expires 1d;\n", file_get_contents($file), 'alter Stand muss zurück sein');
	}

	/**
	 * Existierte vorher keine Datei, muss die neu angelegte bei fehlgeschlagenem Reload wieder verschwinden.
	 */
	public function testSetSnippetRemovesNewFileWhenReloadFails(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('example.com'), null);
		$file = $this->dir . '/www/example.com/conf/custom.conf';
		$this->reloader->failWith = 'nginx -t fehlgeschlagen';
		try {
			$this->service->setSnippet($v, NginxSnippet::fromString("expires 1d;\n"));
			self::fail('Ausnahme erwartet');
		} catch (\RuntimeException) {
		}
		self::assertFileDoesNotExist($file, 'neu angelegte Datei muss wieder weg sein');
	}

	/**
	 * I5 (Abschlussreview): Fehlt der conf/-Ordner (z.B. durch einen manuellen
	 * Eingriff), muss setSnippet() den fehlgeschlagenen file_put_contents() melden,
	 * statt Erfolg zu melden, ohne geschrieben zu haben. Kein Reload, weil nichts
	 * geändert wurde.
	 */
	public function testSetSnippetThrowsWhenTargetDirectoryIsMissing(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('example.com'), null);
		$confDir = $this->dir . '/www/example.com/conf';
		rmdir($confDir);
		$before = $this->reloader->calls;

		try {
			$this->service->setSnippet($v, NginxSnippet::fromString("expires 1d;\n"));
			self::fail('Ausnahme erwartet');
		} catch (\RuntimeException $e) {
			self::assertStringContainsString($confDir, $e->getMessage());
		}
		self::assertSame($before, $this->reloader->calls, 'kein Reload, wenn nichts geschrieben wurde');
		self::assertDirectoryDoesNotExist($confDir);
	}

	/**
	 * Leeres Snippet auf einem vHost ohne vorhandene Datei ändert nichts und löst
	 * keinen Reload aus – auch nicht, wenn der Reloader gerade auf Fehlschlag steht.
	 */
	public function testSetSnippetEmptyOnFreshVhostIsNoOp(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('example.com'), null);
		$file = $this->dir . '/www/example.com/conf/custom.conf';
		$before = $this->reloader->calls;
		$this->reloader->failWith = 'nginx -t fehlgeschlagen: ganz woanders';

		$this->service->setSnippet($v, NginxSnippet::fromString(''));

		self::assertSame($before, $this->reloader->calls, 'wirkungsloser Aufruf darf keinen Reload auslösen');
		self::assertFileDoesNotExist($file);
	}

	/**
	 * Liegt an der Zielstelle ein Symlink (z.B. auf eine fremde Datei außerhalb des
	 * vHosts), wird er nicht angefasst: Ausnahme statt Schreiben, kein Reload.
	 */
	public function testSetSnippetRefusesSymlink(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('example.com'), null);
		$file = $this->dir . '/www/example.com/conf/custom.conf';
		$target = $this->dir . '/ausserhalb.conf';
		file_put_contents($target, "unverändert;\n");
		symlink($target, $file);
		$before = $this->reloader->calls;

		try {
			$this->service->setSnippet($v, NginxSnippet::fromString("expires 1d;\n"));
			self::fail('Ausnahme erwartet');
		} catch (\RuntimeException $e) {
			self::assertStringContainsString('Symlink', $e->getMessage());
		}

		self::assertSame("unverändert;\n", file_get_contents($target), 'Ziel des Symlinks darf nicht verändert werden');
		self::assertSame($before, $this->reloader->calls, 'kein Reload bei verweigertem Schreiben');
	}
	// ------------------------------------------------------------------
	// PHP pro vHost (eigener FPM-Pool, eigener Systembenutzer)
	// ------------------------------------------------------------------

	public function testEnablePhpCreatesUserPoolAndSocketConfiguration(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('php.example'), null);
		$this->service->enablePhp($v);

		$reloaded = $this->repo->byName('php.example');
		self::assertTrue($reloaded->php);
		self::assertSame(
			[$this->layout->baseDir($reloaded)],
			array_values($this->systemUsers->created),
			'Der Systembenutzer muss mit dem Basisordner als Heimatordner angelegt werden'
		);
		self::assertArrayHasKey('web' . $reloaded->id, $this->systemUsers->created);

		$pool = $this->layout->phpPoolFile($reloaded);
		self::assertFileExists($pool);
		self::assertStringContainsString('user = web' . $reloaded->id, (string)file_get_contents($pool));
		self::assertSame(1, $this->fpmReloader->reloads, 'php-fpm muss einmal neu geladen werden');
		// tmp/ für Uploads und php.log müssen existieren, sonst scheitert PHP beim ersten Aufruf.
		self::assertDirectoryExists($this->layout->baseDir($reloaded) . '/tmp');
		self::assertFileExists($this->layout->phpLog($reloaded));
		// Die nginx-Konfiguration muss den Socket jetzt nennen.
		self::assertStringContainsString(
			'fastcgi_pass unix:' . $this->layout->phpSocket($reloaded),
			(string)file_get_contents($this->config->sitesAvailable . '/php.example.conf')
		);
	}

	public function testEnablePhpIsIdempotent(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('php.example'), null);
		$this->service->enablePhp($v);
		$this->service->enablePhp($this->repo->byName('php.example'));
		self::assertCount(1, $this->systemUsers->created);
		self::assertSame(1, $this->fpmReloader->reloads, 'Ein zweiter Aufruf darf nichts mehr tun');
	}

	public function testDisablePhpRemovesThePoolButKeepsTheUser(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('php.example'), null);
		$this->service->enablePhp($v);
		$reloaded = $this->repo->byName('php.example');
		$pool = $this->layout->phpPoolFile($reloaded);

		$this->service->disablePhp($reloaded);

		self::assertFalse($this->repo->byName('php.example')->php);
		self::assertFileDoesNotExist($pool);
		self::assertSame(2, $this->fpmReloader->reloads);
		// Der Benutzer bleibt: Dateien könnten ihm noch gehören, und ein erneutes
		// Einschalten soll ohne neue Kennung auskommen.
		self::assertArrayHasKey('web' . $reloaded->id, $this->systemUsers->created);
		// Ohne PHP wieder die 404-Sperre statt fastcgi.
		$conf = (string)file_get_contents($this->config->sitesAvailable . '/php.example.conf');
		self::assertStringNotContainsString('fastcgi_pass', $conf);
		self::assertStringContainsString('return 404;', $conf);
	}

	/**
	 * Scheitert das Anlegen des Benutzers, darf PHP nicht als "an" gespeichert werden –
	 * sonst schriebe die nächste Ausgabe eine Pool-Datei für einen Benutzer, den es
	 * nicht gibt, und php-fpm verweigerte den Start für ALLE Hosts.
	 */
	public function testEnablePhpLeavesNothingBehindWhenTheUserCannotBeCreated(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('php.example'), null);
		$this->systemUsers->fail = true;

		try {
			$this->service->enablePhp($v);
			self::fail('Hätte scheitern müssen');
		} catch (\RuntimeException) {
			// erwartet
		}

		self::assertFalse($this->repo->byName('php.example')->php);
		self::assertFileDoesNotExist($this->layout->phpPoolFile($v));
		self::assertSame(0, $this->fpmReloader->reloads);
	}

	/**
	 * Schlägt php-fpm -t fehl, muss die Pool-Datei zurückgenommen werden – sonst bliebe
	 * eine kaputte Datei liegen und jeder weitere Reload scheiterte daran.
	 */
	public function testEnablePhpRollsBackWhenFpmRefusesTheConfiguration(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('php.example'), null);
		$this->fpmReloader->fail = true;

		try {
			$this->service->enablePhp($v);
			self::fail('Hätte scheitern müssen');
		} catch (\RuntimeException) {
			// erwartet
		}

		self::assertFileDoesNotExist($this->layout->phpPoolFile($v));
		self::assertFalse($this->repo->byName('php.example')->php);
	}
	/**
	 * Wird ein vHost mit eingeschaltetem PHP gelöscht, muss auch sein Pool weg. Bliebe
	 * die Datei liegen, hielte php-fpm einen Pool für einen Host vor, den es nicht mehr
	 * gibt – samt Socket, auf den nichts mehr zeigt.
	 */
	public function testRemoveAlsoRemovesThePhpPool(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('php.example'), null);
		$this->service->enablePhp($v);
		$withPhp = $this->repo->byName('php.example');
		$pool = $this->layout->phpPoolFile($withPhp);
		self::assertFileExists($pool);

		$this->service->remove($withPhp);

		self::assertFileDoesNotExist($pool);
		self::assertNull($this->repo->byName('php.example'));
	}
	/**
	 * Die Snippet-Datei gehört root und ist für den Besitzer der Website nur lesbar.
	 * Gehörte sie ihm, könnte er sich per chmod selbst Schreibrecht geben und damit am
	 * Admin vorbei nginx-Direktiven setzen.
	 */
	public function testSnippetFileBelongsToRootAndIsGroupReadableOnly(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('conf.example'), null);
		$this->service->setSnippet($v, NginxSnippet::fromString("expires 1d;\n", $this->layout->snippetScope($v)));
		$file = $this->layout->confFile($v);
		self::assertFileExists($file);
		self::assertSame('0640', substr(sprintf('%o', fileperms($file)), -4));
	}
	// ------------------------------------------------------------------
	// ACME-Erreichbarkeit
	// ------------------------------------------------------------------

	public function testRenderCreatesTheAcmeMarkerWithAToken(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('reach.example'), null);
		$reloaded = $this->repo->byName('reach.example');
		self::assertNotNull($reloaded->healthToken, 'Beim Anlegen muss eine Kennung entstehen');
		$marker = $this->layout->healthFile($reloaded);
		self::assertFileExists($marker);
		self::assertSame($reloaded->healthToken, trim((string)file_get_contents($marker)));
	}

	/**
	 * Die Kennung darf sich nicht bei jedem Schreiben ändern – sonst würde ein gerade
	 * laufender Test der Oberfläche gegen eine veraltete Kennung prüfen.
	 */
	public function testTokenStaysTheSameAcrossRenders(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('reach.example'), null);
		$first = $this->repo->byName('reach.example')->healthToken;
		$this->service->render($this->repo->byName('reach.example'));
		self::assertSame($first, $this->repo->byName('reach.example')->healthToken);
	}

	/**
	 * Fehlt der Marker (z.B. weil certbot den Ordner geleert hat), wird er beim nächsten
	 * Schreiben wieder angelegt.
	 */
	public function testMissingMarkerIsRecreated(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('reach.example'), null);
		$reloaded = $this->repo->byName('reach.example');
		unlink($this->layout->healthFile($reloaded));
		$this->service->render($reloaded);
		self::assertFileExists($this->layout->healthFile($reloaded));
	}

	/**
	 * Vor der Zertifikatsausstellung muss die Erreichbarkeit geprüft werden. Ohne diese
	 * Sperre liefe certbot ins Leere und Let's Encrypt zählt den Fehlversuch gegen das
	 * Kontingent der Domain.
	 */
	public function testEnableSslRefusesWhenTheDomainIsNotReachable(): void
	{
		$this->service->setLetsEncryptEmail('admin@example.com');
		$v = $this->service->createDomain(DomainName::fromString('reach.example'), null);
		$this->reachability->status = ReachabilityStatus::WrongServer;

		try {
			$this->service->enableSsl($this->repo->byName('reach.example'));
			self::fail('Hätte abgelehnt werden müssen');
		} catch (\RuntimeException $e) {
			self::assertStringContainsString('reach.example', $e->getMessage());
		}

		self::assertFalse($this->repo->byName('reach.example')->ssl, 'SSL darf nicht gesetzt werden');
		self::assertSame([], $this->certbot->calls, 'certbot darf nicht gelaufen sein');
	}

	public function testEnableSslProceedsWhenReachable(): void
	{
		$this->service->setLetsEncryptEmail('admin@example.com');
		$v = $this->service->createDomain(DomainName::fromString('reach.example'), null);
		$this->reachability->status = ReachabilityStatus::Ok;
		$this->service->enableSsl($this->repo->byName('reach.example'));
		self::assertTrue($this->repo->byName('reach.example')->ssl);
		// Geprüft wurde mit der gespeicherten Kennung dieser Domain.
		$token = $this->repo->byName('reach.example')->healthToken;
		self::assertSame([['reach.example' => $token]], $this->reachability->calls);
	}

	public function testReachabilitySkipsLocalhostHosts(): void
	{
		$local = $this->service->createLocal(Port::fromString('3010'), null);
		$results = $this->service->checkReachability([$this->repo->byName('localhost:3010')]);
		self::assertSame(ReachabilityStatus::NotApplicable, $results['localhost:3010']->status);
		self::assertSame([], $this->reachability->calls, 'Für localhost darf keine Anfrage rausgehen');
	}
	// ------------------------------------------------------------------
	// Entfernen mit Schonfrist
	// ------------------------------------------------------------------

	/**
	 * Nach dem Anstossen liefert nginx den vHost sofort nicht mehr aus – der Eintrag und
	 * alle Dateien bleiben aber bestehen, damit sich das zurückholen lässt.
	 */
	public function testScheduleRemovalDisablesNginxAtOnceButKeepsEverything(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('weg.example'), null);
		$enabled = $this->config->sitesEnabled . '/weg.example.conf';
		self::assertFileExists($enabled);

		$this->service->scheduleRemoval($v);

		self::assertFileDoesNotExist($enabled, 'nginx muss sofort aufhören auszuliefern');
		self::assertGreaterThanOrEqual(1, $this->reloader->calls, 'und dafür neu geladen werden');
		$pending = $this->repo->byName('weg.example');
		self::assertNotNull($pending, 'Der Eintrag bleibt bis zum Ablauf der Frist');
		self::assertTrue($pending->isPendingDeletion());
		self::assertDirectoryExists($this->layout->baseDir($pending), 'Dateien bleiben unangetastet');
		self::assertFileExists($this->config->sitesAvailable . '/weg.example.conf');
	}

	public function testRestoreBringsTheVhostBack(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('weg.example'), null);
		$this->service->scheduleRemoval($v);

		$this->service->restore($this->repo->byName('weg.example'));

		$back = $this->repo->byName('weg.example');
		self::assertFalse($back->isPendingDeletion());
		self::assertFileExists($this->config->sitesEnabled . '/weg.example.conf');
	}

	/**
	 * Ein vorgemerkter vHost darf durch ein Neuschreiben (z.B. install.sh) nicht
	 * versehentlich wieder aktiv werden.
	 */
	public function testRenderKeepsAPendingVhostDisabled(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('weg.example'), null);
		$this->service->scheduleRemoval($v);

		$this->service->renderAll();

		self::assertFileDoesNotExist($this->config->sitesEnabled . '/weg.example.conf');
	}

	public function testPurgeDueRemovesOnlyExpiredEntries(): void
	{
		$frisch = $this->service->createDomain(DomainName::fromString('frisch.example'), null);
		$alt = $this->service->createDomain(DomainName::fromString('alt.example'), null);
		$this->service->scheduleRemoval($frisch);
		$this->service->scheduleRemoval($alt);
		// "alt" vor mehr als einer Stunde vorgemerkt.
		$this->repo->setDeletedAt(
			$this->repo->byName('alt.example')->id,
			(new \DateTimeImmutable('-2 hours', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')
		);

		$removed = $this->service->purgeDue();

		self::assertSame(['alt.example'], $removed);
		self::assertNull($this->repo->byName('alt.example'));
		self::assertNotNull($this->repo->byName('frisch.example'), 'Die frische Vormerkung bleibt');
	}

	/**
	 * Das endgültige Entfernen löscht die Dateien nicht – so wie das Entfernen über die
	 * Oberfläche es noch nie getan hat.
	 */
	public function testPurgeDueKeepsTheFiles(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('alt.example'), null);
		$this->service->scheduleRemoval($v);
		$this->repo->setDeletedAt(
			$this->repo->byName('alt.example')->id,
			(new \DateTimeImmutable('-2 hours', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')
		);
		$base = $this->layout->baseDir($this->repo->byName('alt.example'));

		$this->service->purgeDue();

		self::assertDirectoryExists($base);
	}

	public function testDeletionDueAtIsOneHourAfterScheduling(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('weg.example'), null);
		$this->service->scheduleRemoval($v);
		$pending = $this->repo->byName('weg.example');
		$due = $pending->deletionDueAt($this->config->removalGraceMinutes);
		$expected = new \DateTimeImmutable($pending->deletedAt . ' UTC');
		self::assertSame(3600, $due->getTimestamp() - $expected->getTimestamp());
	}
	// ------------------------------------------------------------------
	// Fertige Konfiguration ansehen
	// ------------------------------------------------------------------

	/**
	 * Die Ansicht soll ein zusammenhängender Text sein: Die Einbindungen, die zu
	 * diesem vHost gehören, werden an Ort und Stelle eingesetzt – sonst müsste man die
	 * Dateien auf dem Server nachschlagen, und genau das soll entfallen.
	 */
	public function testEffectiveConfigInlinesTheVhostsOwnIncludes(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('zeig.example'), null);
		$this->service->addUser($v, Username::fromString('alice'), 'geheim');
		$this->service->setSnippet(
			$this->repo->byName('zeig.example'),
			NginxSnippet::fromString("location = /health {\n    return 200 \"ok\";\n}\n", $this->layout->snippetScope($v))
		);

		$out = $this->service->effectiveConfig($this->repo->byName('zeig.example'));

		// Der Verzeichnisschutz steht jetzt im Text statt als Verweis.
		self::assertStringContainsString('auth_basic', $out);
		self::assertStringContainsString('satisfy any;', $out);
		// Die eigenen Direktiven ebenso.
		self::assertStringContainsString('location = /health {', $out);
		// Und es bleibt kein Verweis auf eine Datei dieses vHosts übrig.
		self::assertStringNotContainsString('include /etc/nginx/auth/', $out);
		self::assertStringNotContainsString('/conf/*.conf;', $out);
		// Die Herkunft wird genannt, damit klar ist, woher ein Abschnitt stammt.
		self::assertStringContainsString('conf/custom.conf', $out);
	}

	/**
	 * fastcgi_params ist eine unveränderliche Systemdatei mit zwanzig Zeilen – die
	 * gehört nicht in die Ansicht, sie wäre nur Rauschen.
	 */
	public function testEffectiveConfigLeavesSystemIncludesAlone(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('zeig.example'), null);
		$this->service->enablePhp($v);
		$out = $this->service->effectiveConfig($this->repo->byName('zeig.example'));
		self::assertStringContainsString('include ' . $this->config->fastcgiParams . ';', $out);
	}

	/**
	 * Ohne eigene Direktiven soll dastehen, dass dort nichts ist – nicht einfach eine
	 * Lücke, bei der man rätselt, ob die Ansicht etwas verschluckt hat.
	 */
	public function testEffectiveConfigSaysWhenThereAreNoOwnDirectives(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('zeig.example'), null);
		$out = $this->service->effectiveConfig($this->repo->byName('zeig.example'));
		self::assertStringContainsString('keine eigenen Direktiven', $out);
	}

	public function testEffectiveConfigFailsLoudlyWithoutAConfiguration(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('zeig.example'), null);
		unlink($this->config->sitesAvailable . '/zeig.example.conf');
		$this->expectException(\RuntimeException::class);
		$this->service->effectiveConfig($this->repo->byName('zeig.example'));
	}
	// ------------------------------------------------------------------
	// Docroot-Unterordner ändern
	// ------------------------------------------------------------------

	/**
	 * Der Inhalt zieht mit: Bliebe er liegen, zeigte nginx nach der Umstellung auf ein
	 * leeres Verzeichnis und die Seite wäre weg, ohne dass ein Fehler erscheint.
	 */
	public function testChangingTheSubdirectoryMovesTheContent(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('um.example'), SubDirectory::fromString('www/src'));
		$old = $this->layout->docroot($this->repo->byName('um.example'));
		file_put_contents($old . '/seite.html', 'inhalt');

		$this->service->setSubdirectory($this->repo->byName('um.example'), SubDirectory::fromString('src/www'));

		$updated = $this->repo->byName('um.example');
		self::assertSame('src/www', $updated->subdir);
		self::assertSame($this->layout->webDir($updated) . '/src/www', $this->layout->docroot($updated));
		self::assertFileExists($this->layout->docroot($updated) . '/seite.html');
		self::assertSame('inhalt', file_get_contents($this->layout->docroot($updated) . '/seite.html'));
		self::assertFileDoesNotExist($old . '/seite.html');
		// Die nginx-Konfiguration muss den neuen Pfad nennen.
		self::assertStringContainsString(
			'root ' . $this->layout->docroot($updated) . ';',
			(string)file_get_contents($this->config->sitesAvailable . '/um.example.conf')
		);
	}

	public function testChangingTheSubdirectoryWritesABackupFirst(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('um.example'), SubDirectory::fromString('www/src'));
		$this->service->setSubdirectory($this->repo->byName('um.example'), SubDirectory::fromString('src/www'));
		self::assertNotEmpty(glob($this->config->backupDir . '/*.tar.gz'), 'Vor dem Verschieben muss gesichert werden');
	}

	public function testSubdirectoryCanBeCleared(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('um.example'), SubDirectory::fromString('www/src'));
		file_put_contents($this->layout->docroot($this->repo->byName('um.example')) . '/seite.html', 'inhalt');

		$this->service->setSubdirectory($this->repo->byName('um.example'), null);

		$updated = $this->repo->byName('um.example');
		self::assertNull($updated->subdir);
		self::assertSame($this->layout->webDir($updated), $this->layout->docroot($updated));
		self::assertFileExists($this->layout->webDir($updated) . '/seite.html');
	}

	public function testUnchangedSubdirectoryDoesNothing(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('um.example'), SubDirectory::fromString('www/src'));
		$before = $this->reloader->calls;
		$this->service->setSubdirectory($this->repo->byName('um.example'), SubDirectory::fromString('www/src'));
		self::assertSame($before, $this->reloader->calls, 'Ohne Änderung darf nichts passieren');
		self::assertEmpty(glob($this->config->backupDir . '/*.tar.gz'), 'und auch nicht gesichert werden');
	}

	/**
	 * Liegt im Ziel schon etwas, wird nicht darüber geschrieben – lieber abbrechen und
	 * den Fall dem Menschen überlassen.
	 */
	public function testChangingTheSubdirectoryRefusesToOverwriteExistingContent(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('um.example'), SubDirectory::fromString('www/src'));
		$reloaded = $this->repo->byName('um.example');
		file_put_contents($this->layout->docroot($reloaded) . '/seite.html', 'alt');
		mkdir($this->layout->webDir($reloaded) . '/src/www', 0775, true);
		file_put_contents($this->layout->webDir($reloaded) . '/src/www/seite.html', 'neu');

		$this->expectException(\RuntimeException::class);
		$this->service->setSubdirectory($reloaded, SubDirectory::fromString('src/www'));
	}
	/**
	 * Der ACME-Pfad hängt an web/, nicht am Docroot. Wanderte er beim Umstellen mit,
	 * käme Let's Encrypt nicht mehr durch und jede Zertifikatserneuerung scheiterte.
	 */
	public function testChangingTheSubdirectoryLeavesTheAcmePathAlone(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('um.example'), null);
		$reloaded = $this->repo->byName('um.example');
		$marker = $this->layout->healthFile($reloaded);
		self::assertFileExists($marker);
		file_put_contents($this->layout->docroot($reloaded) . '/seite.html', 'inhalt');

		$this->service->setSubdirectory($reloaded, SubDirectory::fromString('src/www'));

		$updated = $this->repo->byName('um.example');
		self::assertFileExists($marker, 'Der ACME-Marker muss unter web/ bleiben');
		self::assertFileDoesNotExist($this->layout->docroot($updated) . '/.well-known');
		self::assertFileExists($this->layout->docroot($updated) . '/seite.html', 'Die Seite zieht mit');
	}
	// ------------------------------------------------------------------
	// www-Umleitung
	// ------------------------------------------------------------------

	public function testSetWwwModeWritesTheRedirectAndIsIdempotent(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('um.example'), null);
		$this->service->setWwwMode($v, 'bare');

		$updated = $this->repo->byName('um.example');
		self::assertSame('bare', $updated->wwwMode);
		self::assertSame('www.um.example', $updated->aliasName());
		self::assertSame('um.example', $updated->canonicalName());
		self::assertStringContainsString(
			'return 301 http://um.example$request_uri;',
			(string)file_get_contents($this->config->sitesAvailable . '/um.example.conf')
		);

		$before = $this->reloader->calls;
		$this->service->setWwwMode($this->repo->byName('um.example'), 'bare');
		self::assertSame($before, $this->reloader->calls, 'unveränderter Modus darf nichts tun');
	}

	public function testSetWwwModeRejectsUnknownValues(): void
	{
		$v = $this->service->createDomain(DomainName::fromString('um.example'), null);
		$this->expectException(\InvalidArgumentException::class);
		$this->service->setWwwMode($v, 'vielleicht');
	}

	public function testSetWwwModeRefusedForLocalhostHosts(): void
	{
		$v = $this->service->createLocal(Port::fromString('3011'), null);
		$this->expectException(\RuntimeException::class);
		$this->service->setWwwMode($v, 'bare');
	}

	/**
	 * Wird umgeleitet, muss certbot den Nebennamen mitbeantragen – sonst gibt es einen
	 * Zertifikatsfehler, bevor die Umleitung greift.
	 */
	public function testCertbotIsAskedForTheAliasToo(): void
	{
		$this->service->setLetsEncryptEmail('admin@example.com');
		$v = $this->service->createDomain(DomainName::fromString('um.example'), null);
		$this->service->setWwwMode($v, 'bare');
		$this->service->enableSsl($this->repo->byName('um.example'));

		self::assertSame([['www.um.example']], $this->certbot->alsoFor);
	}

	public function testCertbotIsAskedForOneNameWithoutWwwHandling(): void
	{
		$this->service->setLetsEncryptEmail('admin@example.com');
		$v = $this->service->createDomain(DomainName::fromString('um.example'), null);
		$this->service->enableSsl($this->repo->byName('um.example'));
		self::assertSame([[]], $this->certbot->alsoFor);
	}
}
