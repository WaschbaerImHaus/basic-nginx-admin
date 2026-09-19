<?php
declare(strict_types=1);

/**
 * Tests der Anwendungsfälle mit Temp-Verzeichnissen und Fakes.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-19 14:20
 */

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tests\Support\FakeCertbot;
use Tests\Support\FakeReloader;
use Tests\Support\TempDir;
use VhostAdmin\Config;
use VhostAdmin\Database;
use VhostAdmin\Nginx\ConfigRenderer;
use VhostAdmin\Value\Cidr;
use VhostAdmin\Value\DomainName;
use VhostAdmin\Value\NginxSnippet;
use VhostAdmin\Value\Port;
use VhostAdmin\Value\SubDirectory;
use VhostAdmin\Value\Username;
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
	private FakeCertbot $certbot;
	private VhostLayout $layout;
	private VhostService $service;

	protected function setUp(): void
	{
		$this->dir = TempDir::create();
		mkdir($this->dir . '/avail');
		mkdir($this->dir . '/enabled');
		$this->config = Config::fromArray([
			'dbPath' => $this->dir . '/db.sqlite',
			'wwwRoot' => $this->dir . '/www',
			'wwwOwner' => 'user',
			'sitesAvailable' => $this->dir . '/avail',
			'sitesEnabled' => $this->dir . '/enabled',
			'authDir' => $this->dir . '/auth',
			'letsEncryptLive' => $this->dir . '/le',
			'ipv6' => false,
		]);
		$db = new Database($this->config);
		$db->initSchema();
		$this->repo = new VhostRepository($db);
		$this->reloader = new FakeReloader();
		$this->certbot = new FakeCertbot($this->dir . '/le');
		$this->layout = new VhostLayout($this->config);
		$this->service = new VhostService(
			$this->config, $this->repo, new ConfigRenderer($this->config, $this->layout),
			$this->reloader, $this->certbot, $this->layout
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
		self::assertSame([['example.com', $this->dir . '/www/example.com', 'admin@example.com']], $this->certbot->calls);
		self::assertTrue($this->service->load('example.com')->ssl);
		$conf = (string)file_get_contents($this->dir . '/avail/example.com.conf');
		self::assertStringContainsString('listen 443 ssl;', $conf);
		self::assertStringContainsString('return 301 https://', $conf);
		$this->service->disableSsl($this->service->load('example.com'));
		self::assertFalse($this->service->load('example.com')->ssl);
		self::assertStringNotContainsString('443', (string)file_get_contents($this->dir . '/avail/example.com.conf'));
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
}
