<?php
declare(strict_types=1);

/**
 * Tests für die Erzeugung der nginx-Konfiguration.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 11:15
 */

namespace Tests\Nginx;

use PHPUnit\Framework\TestCase;
use VhostAdmin\Config;
use VhostAdmin\Nginx\ConfigRenderer;
use VhostAdmin\Vhost;
use VhostAdmin\VhostKind;
use VhostAdmin\VhostLayout;

final class ConfigRendererTest extends TestCase
{
	private function renderer(bool $ipv6 = true): ConfigRenderer
	{
		$config = Config::fromArray([
			'wwwRoot' => '/var/www', 'wwwOwner' => 'user', 'authDir' => '/etc/nginx/auth',
			'sitesAvailable' => '/etc/nginx/sites-available', 'letsEncryptLive' => '/etc/letsencrypt/live',
			'ipv6' => $ipv6,
		]);
		return new ConfigRenderer($config, new VhostLayout($config));
	}

	public function testPaths(): void
	{
		$v = new Vhost(1, 'localhost:3000', VhostKind::Localhost, 3000, null, true, false);
		self::assertSame('/etc/nginx/auth/localhost-3000.htpasswd', $this->renderer()->htpasswdPath($v));
		self::assertSame('/etc/nginx/auth/localhost-3000.conf', $this->renderer()->authSnippetPath($v));
		self::assertSame('/etc/nginx/sites-available/localhost-3000.conf', $this->renderer()->serverConfigPath($v));
	}

	public function testHtpasswdLines(): void
	{
		self::assertSame('', $this->renderer()->htpasswd([]));
		self::assertSame(
			"alice:\$6\$abc\nbob:\$6\$def\n",
			$this->renderer()->htpasswd([['username' => 'alice', 'hash' => '$6$abc'], ['username' => 'bob', 'hash' => '$6$def']])
		);
	}

	public function testAuthSnippetEnabledWithIps(): void
	{
		$v = new Vhost(1, 'example.com', VhostKind::Domain, null, null, true, false);
		$expected = "# generiert von vhost – nicht manuell bearbeiten\n"
			. "satisfy any;\n"
			. "allow 10.0.0.0/8;\n"
			. "allow 203.0.113.5;\n"
			. "deny all;\n"
			. "auth_basic \"Geschützter Bereich\";\n"
			. "auth_basic_user_file /etc/nginx/auth/example.com.htpasswd;\n";
		self::assertSame($expected, $this->renderer()->authSnippet($v, ['10.0.0.0/8', '203.0.113.5']));
	}

	public function testAuthSnippetDisabled(): void
	{
		$v = new Vhost(1, 'example.com', VhostKind::Domain, null, null, false, false);
		self::assertSame(
			"# generiert von vhost – nicht manuell bearbeiten\n# Verzeichnisschutz deaktiviert\n",
			$this->renderer()->authSnippet($v, ['10.0.0.0/8'])
		);
	}

	public function testDomainWithoutSsl(): void
	{
		$v = new Vhost(1, 'example.com', VhostKind::Domain, null, 'public', true, false);
		$expected = <<<NG
# generiert von vhost – nicht manuell bearbeiten
server {
    listen 80;
    listen [::]:80;
    server_name example.com;
    location ^~ /.well-known/acme-challenge/ {
        auth_basic off;
        allow all;
        root /var/www/example.com/web;
    }
    root /var/www/example.com/web/public;
    index index.html index.htm;
    access_log /var/www/example.com/logs/access.log;
    error_log  /var/www/example.com/logs/error.log;
    include /etc/nginx/auth/example.com.conf;
    include /var/www/example.com/conf/*.conf;
    location / {
        try_files \$uri \$uri/ =404;
    }
}

NG;
		self::assertSame($expected, $this->renderer()->serverConfig($v));
	}

	public function testDomainWithSslRedirectsAndServes443(): void
	{
		$v = new Vhost(1, 'example.com', VhostKind::Domain, null, null, true, true);
		$out = $this->renderer()->serverConfig($v);
		self::assertStringContainsString("    location / {\n        return 301 https://\$host\$request_uri;\n    }\n}\n", $out);
		self::assertStringContainsString("    listen 443 ssl;\n    listen [::]:443 ssl;\n    http2 on;\n", $out);
		self::assertStringContainsString('ssl_certificate     /etc/letsencrypt/live/example.com/fullchain.pem;', $out);
		self::assertStringContainsString('ssl_certificate_key /etc/letsencrypt/live/example.com/privkey.pem;', $out);
		self::assertStringContainsString("ssl_protocols TLSv1.2 TLSv1.3;", $out);
		self::assertSame(2, substr_count($out, 'server {'));
		// Der ACME-Pfad gehört nur in den Port-80-Block: http-01 fragt immer über HTTP an, und "location ^~" hat dort Vorrang vor der Weiterleitung.
		self::assertSame(1, substr_count($out, 'acme-challenge'));
		self::assertSame(1, substr_count($out, 'include /etc/nginx/auth/example.com.conf;'));
		self::assertStringContainsString('root /var/www/example.com/web;', $out);
		self::assertSame(1, substr_count($out, 'include /var/www/example.com/conf/*.conf;'));
		self::assertStringContainsString('access_log /var/www/example.com/logs/access.log;', $out);
	}

	public function testLocalhostBindsLoopbackOnly(): void
	{
		$v = new Vhost(2, 'localhost:3000', VhostKind::Localhost, 3000, null, false, false);
		$expected = <<<NG
# generiert von vhost – nicht manuell bearbeiten
server {
    listen 127.0.0.1:3000;
    listen [::1]:3000;
    server_name localhost;
    root /var/www/localhost-3000/web;
    index index.html index.htm;
    access_log /var/www/localhost-3000/logs/access.log;
    error_log  /var/www/localhost-3000/logs/error.log;
    include /etc/nginx/auth/localhost-3000.conf;
    include /var/www/localhost-3000/conf/*.conf;
    location / {
        try_files \$uri \$uri/ =404;
    }
}

NG;
		self::assertSame($expected, $this->renderer()->serverConfig($v));
	}

	public function testAcmeLocationPointsToWebDirectoryEvenWithSubdirectory(): void
	{
		$v = new Vhost(1, 'example.com', VhostKind::Domain, null, 'public', true, false);
		$out = $this->renderer()->serverConfig($v);
		self::assertStringContainsString("        root /var/www/example.com/web;\n", $out);
		self::assertStringContainsString('    root /var/www/example.com/web/public;', $out);
	}

	public function testWithoutIpv6NoBracketListens(): void
	{
		$domain = new Vhost(1, 'example.com', VhostKind::Domain, null, null, true, true);
		$local = new Vhost(2, 'localhost:3000', VhostKind::Localhost, 3000, null, true, false);
		self::assertStringNotContainsString('[::', $this->renderer(false)->serverConfig($domain));
		self::assertStringNotContainsString('[::', $this->renderer(false)->serverConfig($local));
	}
}
