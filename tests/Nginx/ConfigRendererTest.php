<?php
declare(strict_types=1);

/**
 * Tests für die Erzeugung der nginx-Konfiguration.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-19 14:12
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
    try_files \$uri \$uri/ =404;

    access_log /var/www/example.com/logs/access.log;
    error_log  /var/www/example.com/logs/error.log;

    include /etc/nginx/auth/example.com.conf;

    location ~ /\.(?!well-known/) {
        deny all;
        access_log off;
        log_not_found off;
    }

    location = /favicon.ico {
        log_not_found off;
        access_log off;
    }

    location = /robots.txt {
        allow all;
        log_not_found off;
        access_log off;
    }

    location ~ \.php\$ {
        return 404;
    }

    # >>>>
    # ab hier eigene Direktiven (Oberfläche: "Eigene Direktiven")
    include /var/www/example.com/conf/*.conf;
    # <<<<
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

	/**
	 * Worum es hier geht: ein localhost-Host darf ausschliesslich auf dem Loopback
	 * lauschen, nie öffentlich. Der übrige Aufbau des Blocks wird in
	 * testDomainWithoutSsl() im Wortlaut geprüft – hier nur die Bindung, damit beide
	 * Tests nicht denselben Text doppelt festschreiben.
	 */
	public function testLocalhostBindsLoopbackOnly(): void
	{
		$v = new Vhost(2, 'localhost:3000', VhostKind::Localhost, 3000, null, false, false);
		$out = $this->renderer()->serverConfig($v);
		self::assertStringContainsString("    listen 127.0.0.1:3000;\n    listen [::1]:3000;\n", $out);
		self::assertStringContainsString("    server_name localhost;\n", $out);
		self::assertStringNotContainsString('listen 80', $out);
		self::assertStringNotContainsString('listen [::]:', $out);
		self::assertStringNotContainsString('acme-challenge', $out);
		self::assertStringContainsString('root /var/www/localhost-3000/web;', $out);
		self::assertSame(1, substr_count($out, 'server {'));

		// Ohne IPv6 auf dem System entfällt die [::1]-Zeile, die IPv4-Bindung bleibt.
		$withoutIpv6 = $this->renderer(false)->serverConfig($v);
		self::assertStringContainsString('listen 127.0.0.1:3000;', $withoutIpv6);
		self::assertStringNotContainsString('[::1]', $withoutIpv6);
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
	/**
	 * Der eigene Bereich liegt wie bei ISPConfig am Ende des server-Blocks, zwischen
	 * Markern. Nur so lässt sich ein Fragment aus einer fremden conf einsetzen, ohne
	 * mit den vorgegebenen Direktiven zu kollidieren.
	 */
	public function testUserSectionIsTheLastThingInTheServerBlock(): void
	{
		$v = new Vhost(1, 'example.com', VhostKind::Domain, null, null, true, false);
		$out = $this->renderer()->serverConfig($v);
		$expected = "    # >>>>\n"
			. "    # ab hier eigene Direktiven (Oberfläche: \"Eigene Direktiven\")\n"
			. "    include /var/www/example.com/conf/*.conf;\n"
			. "    # <<<<\n"
			. "}\n";
		self::assertStringEndsWith($expected, $out);
	}

	/**
	 * try_files steht auf Server-Ebene, nicht in einem eigenen "location /". Sonst
	 * kollidiert ein eingefügtes eigenes "location / { … }" mit "duplicate location" –
	 * genau der Fall, den copy-paste auslöst.
	 */
	public function testNoGeneratedRootLocationSoUsersCanDefineTheirOwn(): void
	{
		$v = new Vhost(1, 'example.com', VhostKind::Domain, null, null, true, false);
		$out = $this->renderer()->serverConfig($v);
		self::assertStringContainsString("    try_files \$uri \$uri/ =404;\n", $out);
		self::assertStringNotContainsString('location / {', $out);
	}

	public function testStandardBlocksFromTheWrapperArePresent(): void
	{
		$v = new Vhost(1, 'example.com', VhostKind::Domain, null, null, true, false);
		$out = $this->renderer()->serverConfig($v);
		self::assertStringContainsString("location ~ /\\.(?!well-known/) {\n        deny all;", $out);
		self::assertStringContainsString('location = /favicon.ico {', $out);
		self::assertStringContainsString('location = /robots.txt {', $out);
		self::assertStringContainsString("    access_log /var/www/example.com/logs/access.log;\n", $out);
	}

	/**
	 * Ohne PHP muss eine .php-Datei mit 404 abgewiesen werden statt im Klartext
	 * ausgeliefert zu werden – sonst liegt der Quellcode samt Zugangsdaten offen.
	 */
	public function testPhpFilesAreRefusedWhenPhpIsOff(): void
	{
		$v = new Vhost(1, 'example.com', VhostKind::Domain, null, null, true, false, false);
		$out = $this->renderer()->serverConfig($v);
		self::assertStringContainsString("location ~ \\.php\$ {\n        return 404;\n    }\n", $out);
		self::assertStringNotContainsString('fastcgi_pass', $out);
		self::assertStringNotContainsString('index.php', $out);
	}

	public function testPhpBlockUsesTheOwnPoolSocketWhenPhpIsOn(): void
	{
		$v = new Vhost(7, 'example.com', VhostKind::Domain, null, null, true, false, true);
		$out = $this->renderer()->serverConfig($v);
		self::assertStringContainsString('    index index.html index.htm index.php;', $out);
		self::assertStringContainsString('fastcgi_pass unix:/run/php/vhost-example.com.sock;', $out);
		self::assertStringContainsString('fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;', $out);
		self::assertStringNotContainsString('return 404;', $out);
	}

	/**
	 * HTTP/3 nur bei Hosts mit Zertifikat – ohne TLS gibt es kein QUIC.
	 */
	public function testHttp3IsOnlyAddedForSslHosts(): void
	{
		$withSsl = new Vhost(1, 'example.com', VhostKind::Domain, null, null, true, true);
		$out = $this->renderer()->serverConfig($withSsl);
		self::assertStringContainsString("    listen 443 quic;\n", $out);
		self::assertStringContainsString("    http3 on;\n", $out);
		self::assertStringContainsString("add_header Alt-Svc 'h3=\":443\"; ma=86400';", $out);

		$withoutSsl = new Vhost(1, 'example.com', VhostKind::Domain, null, null, true, false);
		self::assertStringNotContainsString('quic', $this->renderer()->serverConfig($withoutSsl));
	}

	/**
	 * Die Zertifikate kommen direkt aus /etc/letsencrypt/live, NICHT über die Symlinks
	 * in cert/: eine defekte Symlink-Kette würde nginx am Start hindern. cert/ ist
	 * reine Sichtbarkeit (siehe VhostService::linkCertificates()).
	 */
	public function testSslCertificatesComeFromLetsEncryptDirectly(): void
	{
		$v = new Vhost(1, 'example.com', VhostKind::Domain, null, null, true, true);
		$out = $this->renderer()->serverConfig($v);
		self::assertStringContainsString('ssl_certificate     /etc/letsencrypt/live/example.com/fullchain.pem;', $out);
		self::assertStringNotContainsString('/cert/', $out);
	}

	public function testLocalhostHostAlsoGetsTheUserSectionAndPhp(): void
	{
		$v = new Vhost(3, 'localhost:3000', VhostKind::Localhost, 3000, null, false, false, true);
		$out = $this->renderer()->serverConfig($v);
		self::assertStringContainsString("    listen 127.0.0.1:3000;\n", $out);
		self::assertStringContainsString('fastcgi_pass unix:/run/php/vhost-localhost-3000.sock;', $out);
		self::assertStringEndsWith("    # <<<<\n}\n", $out);
	}
}
