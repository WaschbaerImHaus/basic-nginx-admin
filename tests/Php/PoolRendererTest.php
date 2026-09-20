<?php
declare(strict_types=1);

/**
 * Tests für die php-fpm-Pool-Datei eines vHosts.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-20 21:55
 */

namespace Tests\Php;

use PHPUnit\Framework\TestCase;
use VhostAdmin\Config;
use VhostAdmin\Php\PoolRenderer;
use VhostAdmin\Vhost;
use VhostAdmin\VhostKind;
use VhostAdmin\VhostLayout;

final class PoolRendererTest extends TestCase
{
	private PoolRenderer $renderer;

	protected function setUp(): void
	{
		$config = Config::fromArray(['wwwRoot' => '/var/www', 'wwwOwner' => 'user']);
		$this->renderer = new PoolRenderer(new VhostLayout($config));
	}

	public function testPoolRunsAsItsOwnUserAndIsReachableByNginxOnly(): void
	{
		$v = new Vhost(7, 'example.com', VhostKind::Domain, null, null, true, false, true);
		$out = $this->renderer->render($v);
		self::assertStringStartsWith("; generiert von vhost", $out);
		self::assertStringContainsString("[vhost-example.com]\n", $out);
		self::assertStringContainsString("user = web7\n", $out);
		self::assertStringContainsString("group = web7\n", $out);
		self::assertStringContainsString("listen = /run/php/vhost-example.com.sock\n", $out);
		// Den Socket darf nur nginx öffnen; der Pool selbst läuft als web7.
		self::assertStringContainsString("listen.owner = www-data\n", $out);
		self::assertStringContainsString("listen.mode = 0660\n", $out);
		// Auf keinen Fall www-data als Pool-Benutzer: das hätte über sudoers root-Rechte.
		self::assertStringNotContainsString("user = www-data\n", $out);
	}

	/**
	 * open_basedir muss PHP auf den eigenen vHost eingrenzen – sonst könnte ein Skript
	 * die Dateien anderer Hosts und der Oberfläche lesen.
	 */
	public function testOpenBasedirIsLimitedToTheVhost(): void
	{
		$v = new Vhost(7, 'example.com', VhostKind::Domain, null, null, true, false, true);
		$out = $this->renderer->render($v);
		self::assertStringContainsString(
			'php_admin_value[open_basedir] = /var/www/example.com/web:/var/www/example.com/private:/var/www/example.com/tmp',
			$out
		);
		self::assertStringContainsString('php_admin_value[error_log] = /var/www/example.com/logs/php.log', $out);
		self::assertStringContainsString("php_admin_flag[display_errors] = off\n", $out);
		// conf/ und logs/ gehören root und dürfen für PHP nicht erreichbar sein.
		self::assertStringNotContainsString('/example.com/conf', $out);
		self::assertStringNotContainsString("open_basedir] = /var/www/example.com\n", $out);
	}

	public function testPoolNameAndSocketFollowTheSlugForLocalhostHosts(): void
	{
		$v = new Vhost(3, 'localhost:3000', VhostKind::Localhost, 3000, null, false, false, true);
		self::assertSame('vhost-localhost-3000', $this->renderer->poolName($v));
		self::assertStringContainsString("listen = /run/php/vhost-localhost-3000.sock\n", $this->renderer->render($v));
		self::assertStringContainsString("user = web3\n", $this->renderer->render($v));
	}
}
