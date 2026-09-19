<?php
declare(strict_types=1);

/**
 * Erzeugt nginx-Konfigurationstexte für einen vHost – ohne Dateizugriff.
 *
 * Domains lauschen öffentlich auf 80 (und 443 mit Zertifikat), localhost-Hosts
 * nur auf 127.0.0.1/[::1]. Der ACME-Pfad ist immer frei erreichbar, damit
 * certbot auch bei aktivem Verzeichnisschutz durchkommt.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-19 09:25
 */

namespace VhostAdmin\Nginx;

use VhostAdmin\Config;
use VhostAdmin\Vhost;
use VhostAdmin\VhostLayout;

final class ConfigRenderer
{
	private const HEADER = "# generiert von vhost – nicht manuell bearbeiten\n";

	/**
	 * Übernimmt die Konfiguration, aus der alle Pfade abgeleitet werden.
	 */
	public function __construct(private readonly Config $config)
	{
	}

	/**
	 * Pfad der htpasswd-Datei des vHosts.
	 */
	public function htpasswdPath(Vhost $v): string
	{
		return $this->config->authDir . '/' . $v->slug() . '.htpasswd';
	}

	/**
	 * Pfad des Auth-Snippets, das der Server-Block einbindet.
	 */
	public function authSnippetPath(Vhost $v): string
	{
		return $this->config->authDir . '/' . $v->slug() . '.conf';
	}

	/**
	 * Pfad der Server-Konfiguration in sites-available.
	 */
	public function serverConfigPath(Vhost $v): string
	{
		return $this->config->sitesAvailable . '/' . $v->slug() . '.conf';
	}

	/**
	 * Inhalt der htpasswd-Datei: eine Zeile "benutzer:hash" je Benutzer.
	 *
	 * @param list<array{username: string, hash: string}> $users
	 */
	public function htpasswd(array $users): string
	{
		$lines = '';
		foreach ($users as $user) {
			$lines .= $user['username'] . ':' . $user['hash'] . "\n";
		}
		return $lines;
	}

	/**
	 * Auth-Snippet: freigegebene IPs ODER gültiger Login (satisfy any).
	 * Ohne IPs und ohne Benutzer ist der Docroot komplett gesperrt.
	 *
	 * @param list<string> $ips
	 */
	public function authSnippet(Vhost $v, array $ips): string
	{
		if (!$v->protect) {
			return self::HEADER . "# Verzeichnisschutz deaktiviert\n";
		}
		$out = self::HEADER . "satisfy any;\n";
		foreach ($ips as $ip) {
			$out .= "allow $ip;\n";
		}
		return $out . "deny all;\nauth_basic \"Geschützter Bereich\";\nauth_basic_user_file " . $this->htpasswdPath($v) . ";\n";
	}

	/**
	 * Vollständige Server-Konfiguration des vHosts.
	 */
	public function serverConfig(Vhost $v): string
	{
		$slug = $v->slug();
		// vorläufig ohne web/: stellt Task 3/4 um
		$root = (new VhostLayout($this->config))->baseDir($v) . ($v->subdir !== null ? '/' . $v->subdir : '');
		$authInclude = $this->authSnippetPath($v);
		$common = <<<NG
    root $root;
    index index.html index.htm;
    access_log /var/log/nginx/$slug.access.log;
    error_log  /var/log/nginx/$slug.error.log;
    include $authInclude;
    location / {
        try_files \$uri \$uri/ =404;
    }
NG;

		if ($v->isLocal()) {
			$listen = "    listen 127.0.0.1:{$v->port};\n" . ($this->config->ipv6 ? "    listen [::1]:{$v->port};\n" : '');
			return self::HEADER . "server {\n$listen    server_name localhost;\n$common\n}\n";
		}

		// Vorläufig lokal instanziiert statt über den Konstruktor: Task 3 löst das ab.
		$base = (new VhostLayout($this->config))->baseDir($v);
		$acme = <<<NG
    location ^~ /.well-known/acme-challenge/ {
        auth_basic off;
        allow all;
        root $base;
    }
NG;
		$listen80 = "    listen 80;\n" . ($this->config->ipv6 ? "    listen [::]:80;\n" : '');
		if (!$v->ssl) {
			return self::HEADER . "server {\n$listen80    server_name {$v->name};\n$acme\n$common\n}\n";
		}

		$listen443 = "    listen 443 ssl;\n" . ($this->config->ipv6 ? "    listen [::]:443 ssl;\n" : '') . "    http2 on;\n";
		$live = $this->config->letsEncryptLive . '/' . $v->name;
		$ssl = <<<NG
    ssl_certificate     $live/fullchain.pem;
    ssl_certificate_key $live/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 1d;
NG;
		return self::HEADER
			. "server {\n$listen80    server_name {$v->name};\n$acme\n    location / {\n        return 301 https://\$host\$request_uri;\n    }\n}\n"
			. "server {\n$listen443    server_name {$v->name};\n$ssl\n$common\n}\n";
	}
}
