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
 * @version Letzte Änderung: 2026-09-20 22:30
 */

namespace VhostAdmin\Nginx;

use VhostAdmin\Value\ProtectPath;

use VhostAdmin\Config;
use VhostAdmin\Vhost;
use VhostAdmin\VhostLayout;

final class ConfigRenderer
{
	private const HEADER = "# generiert von vhost – nicht manuell bearbeiten\n";

	/**
	 * Gehört in jeden server-Block, auch in die reinen Umleitungsblöcke: sonst nennt
	 * ausgerechnet die Umleitung die nginx-Version, die der ausliefernde Block verschweigt.
	 */
	private const HIDE_VERSION = "    server_tokens off;\n";

	/**
	 * @param Config      $config Pfade der Installation
	 * @param VhostLayout $layout Quelle für Docroot, Logdateien und Snippet-Ordner
	 */
	public function __construct(private readonly Config $config, private readonly VhostLayout $layout)
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
	 * Auth-Snippet (Server-Ebene): Anmeldung, wo die Realm-Variable nicht "off" ist.
	 *
	 * Seit 2026-09-25 kein satisfy/allow/deny mehr. Wer sich anmelden muss, entscheiden
	 * geo und map am Kopf der Server-Konfiguration (accessMaps()): freigegebene Adresse
	 * ODER Login, und nur innerhalb des geschützten Pfads. auth_basic wertet eine
	 * Variable bei jeder Anfrage aus; ergibt sie "off", entfällt die Anmeldung. Das gilt
	 * für jede Anfrage, auch für PHP – ein location-Block für den Pfad hätte dagegen
	 * zwei Fallen: Als Präfix verlöre er /admin/x.php an den PHP-Block (Regex gewinnt,
	 * PHP ungeschützt), mit ^~ griffe der PHP-Block nicht mehr (Quelltext ausgeliefert).
	 */
	public function authSnippet(Vhost $v): string
	{
		if (!$v->protect) {
			return self::HEADER . "# Verzeichnisschutz deaktiviert\n";
		}
		$realm = $this->variable($v, 'realm');
		return self::HEADER
			. "# Anmeldung nur, wo \$$realm nicht \"off\" ist (Kopf der Server-Konfiguration).\n"
			. "auth_basic \$$realm;\n"
			. 'auth_basic_user_file ' . $this->htpasswdPath($v) . ";\n";
	}

	/**
	 * geo und map für den Verzeichnisschutz (http-Ebene; leer ohne Schutz).
	 *
	 *   ip    1 = Anfrage von einer freigegebenen Adresse (geo auf $remote_addr)
	 *   path  1 = Anfrage im geschützten Bereich (map auf $uri)
	 *   realm Anmeldung nötig genau bei ip=0 und path=1, sonst "off"
	 *
	 * $uri ist von nginx dekodiert und normalisiert: "//admin", "/x/../admin" und
	 * "/%61dmin" landen beim selben Pfad (am 2026-09-25 gegen eine Wegwerf-Instanz
	 * geprüft). Grenze: Eine eigene rewrite- oder return-Direktive wirkt in einer Phase
	 * VOR der Zugriffsprüfung und kann den Pfad vorher umlenken.
	 *
	 * @param list<string> $ips freigegebene Adressen und Netze
	 */
	public function accessMaps(Vhost $v, array $ips): string
	{
		if (!$v->protect) {
			return '';
		}
		$ip = $this->variable($v, 'ip');
		$path = $this->variable($v, 'path');
		$realm = $this->variable($v, 'realm');

		$out = "# Verzeichnisschutz: Anmeldung nötig, wer nicht von einer freigegebenen Adresse\n"
			. '# kommt und ' . ($v->protectPath === null ? 'die Seite' : $v->protectPath . ' oder darunter') . " aufruft.\n"
			. "geo \$$ip {\n    default 0;\n";
		foreach ($ips as $allowed) {
			$out .= "    $allowed 1;\n";
		}
		$out .= "}\n";
		$out .= "map \$uri \$$path {\n";
		if ($v->protectPath === null) {
			$out .= "    default 1;\n";
		} else {
			// Aus der Datenbank neu geprüft (Vhost::assertConsistent()), bevor der Wert
			// hier als Ausdruck landet.
			$out .= "    default 0;\n    " . ProtectPath::fromString($v->protectPath)?->pattern() . " 1;\n";
		}
		$out .= "}\n";
		$out .= "map \$$ip\$$path \$$realm {\n    default off;\n    01 \"Geschützter Bereich\";\n}\n";
		return $out;
	}

	/**
	 * Name einer nginx-Variable dieses vHosts. Über die ID, nicht den Namen: Variablen
	 * gelten für ganz nginx und dürfen nur Buchstaben, Ziffern und _ enthalten.
	 */
	private function variable(Vhost $v, string $purpose): string
	{
		if ($v->id === null) {
			throw new \RuntimeException('Verzeichnisschutz braucht einen gespeicherten vHost (keine ID).');
		}
		return 'vhostadmin_' . $v->id . '_' . $purpose;
	}

	/**
	 * Vollständige Server-Konfiguration des vHosts (Parameter $ips: freigegebene
	 * Adressen für den Verzeichnisschutz, siehe accessMaps()).
	 *
	 * Aufbau nach dem Vorbild von ISPConfig (Nutzerwunsch vom 2026-09-20): Der
	 * generierte Teil gibt möglichst viel vor, der eigene Bereich des Nutzers steht als
	 * Letztes im server-Block zwischen den Markern "# >>>>" und "# <<<<". Nur so lässt
	 * sich ein Fragment aus der conf eines anderen Projekts einsetzen, ohne mit den
	 * vorgegebenen Direktiven zu kollidieren.
	 *
	 * Bewusst kein eigenes "location / { … }": try_files steht stattdessen auf
	 * Server-Ebene. Ein eingefügtes eigenes "location /" würde sonst mit
	 * "duplicate location" scheitern – genau der Fall, den copy-paste auslöst.
	 *
	 * @param bool $hsts ob Strict-Transport-Security gesendet werden darf (nur bei SSL
	 *                   überhaupt relevant); abschaltbar, weil die Kopfzeile im Browser
	 *                   monatelang nachwirkt
	 */
	public function serverConfig(Vhost $v, bool $hsts = true, array $ips = []): string
	{
		// geo/map des Verzeichnisschutzes gehören auf die http-Ebene. Diese Datei wird
		// dort eingebunden, also stehen sie hier vor dem ersten server-Block.
		$servers = $this->servers($v, $hsts);
		$maps = $this->accessMaps($v, $ips);
		if ($maps === '') {
			return $servers;
		}
		$body = str_starts_with($servers, self::HEADER) ? substr($servers, strlen(self::HEADER)) : $servers;
		return self::HEADER . $maps . "\n" . $body;
	}

	/**
	 * Die server-Blöcke des vHosts (ohne geo/map).
	 */
	private function servers(Vhost $v, bool $hsts): string
	{
		if ($v->isLocal()) {
			$listen = "    listen 127.0.0.1:{$v->port};\n"
				. ($this->config->ipv6 ? "    listen [::1]:{$v->port};\n" : '');
			return self::HEADER . "server {\n" . $listen . "    server_name localhost;\n\n" . $this->body($v) . "}\n";
		}

		// Beide Namen zeigen auf denselben Docroot; ein Verzeichnis "www.<domain>" gibt
		// es nie. Unterschieden wird nur, welcher Name ausliefert und welcher umleitet.
		$canonical = $v->canonicalName();
		$alias = $v->aliasName();

		$listen80 = "    listen 80;\n" . ($this->config->ipv6 ? "    listen [::]:80;\n" : '');
		if (!$v->ssl) {
			$out = self::HEADER;
			if ($alias !== null) {
				// Eigener :80-Block für den Nebennamen – mit ACME-Pfad, sonst kann
				// certbot ihn nicht prüfen und das Zertifikat deckt ihn nicht ab.
				$out .= "server {\n" . $listen80 . "    server_name $alias;\n"
					. self::HIDE_VERSION . "\n"
					. $this->acme($v) . "\n"
					. "    location / {\n        return 301 http://$canonical\$request_uri;\n    }\n}\n\n";
			}
			return $out . "server {\n" . $listen80 . "    server_name $canonical;\n\n"
				. $this->acme($v) . "\n" . $this->body($v) . "}\n";
		}

		// Mit Zertifikat nimmt der :80-Block beide Namen und leitet unmittelbar auf den
		// kanonischen über HTTPS um – ein Umweg über den Nebennamen auf 443 wäre eine
		// zweite Umleitung für nichts. Ohne www-Umgang bleibt $host stehen: es gibt nur
		// einen Namen, und das spart eine Fallunterscheidung.
		$names80 = $alias === null ? $canonical : "$canonical $alias";
		$target80 = $alias === null ? '$host' : $canonical;
		$redirect = self::HEADER
			. "server {\n" . $listen80 . "    server_name $names80;\n"
			. self::HIDE_VERSION . "\n"
			. $this->acme($v) . "\n"
			. "    location / {\n        return 301 https://$target80\$request_uri;\n    }\n}\n\n";

		$listen443 = "    listen 443 ssl;\n" . ($this->config->ipv6 ? "    listen [::]:443 ssl;\n" : '')
			. "    http2 on;\n"
			// HTTP/3 braucht QUIC auf demselben Port; dieses nginx ist mit
			// --with-http_v3_module gebaut. Der Alt-Svc-Header sagt dem Browser, dass er
			// die Folgeanfragen über h3 stellen darf.
			. "    listen 443 quic;\n" . ($this->config->ipv6 ? "    listen [::]:443 quic;\n" : '')
			. "    http3 on;\n"
			// "always": ohne den Zusatz sendet nginx die Kopfzeile nur bei 2xx/3xx – auf
			// einem Host mit Verzeichnisschutz (401) würde HTTP/3 also nie angekündigt.
			. "    add_header Alt-Svc 'h3=\":443\"; ma=86400' always;\n";
		// Bewusst direkt nach /etc/letsencrypt/live und nicht über die Symlinks in cert/:
		// eine defekte Symlink-Kette würde nginx am Start hindern (siehe
		// VhostService::linkCertificates(), cert/ ist reine Sichtbarkeit).
		$live = $this->config->letsEncryptLive . '/' . $v->name;
		$ssl = "    ssl_certificate     $live/fullchain.pem;\n"
			. "    ssl_certificate_key $live/privkey.pem;\n"
			. "    ssl_protocols TLSv1.2 TLSv1.3;\n"
			. "    ssl_prefer_server_ciphers off;\n"
			. "    ssl_session_cache shared:SSL:10m;\n"
			. "    ssl_session_timeout 1d;\n";

		$aliasBlock = '';
		if ($alias !== null) {
			// Der Nebenname muss auch über HTTPS antworten: Wer https://<neben> aufruft,
			// bekommt sonst einen Zertifikatsfehler, bevor irgendeine Umleitung greift.
			// Deshalb muss das Zertifikat beide Namen abdecken.
			$aliasBlock = "server {\n" . $listen443 . "\n    server_name $alias;\n"
				. self::HIDE_VERSION . "\n" . $ssl . "\n"
				. "    location / {\n        return 301 https://$canonical\$request_uri;\n    }\n}\n\n";
		}

		// Der ACME-Pfad gehört nur in den :80-Block: http-01 fragt immer über HTTP an, und
		// dort hat "location ^~" Vorrang vor der Weiterleitung.
		return $redirect . $aliasBlock
			. "server {\n" . $listen443 . "\n    server_name $canonical;\n\n" . $ssl . "\n"
			. $this->body($v, $hsts) . "}\n";
	}

	/**
	 * Der ACME-Pfad, immer ohne Verzeichnisschutz, damit certbot durchkommt.
	 */
	private function acme(Vhost $v): string
	{
		$webDir = $this->layout->webDir($v);
		return "    location ^~ /.well-known/acme-challenge/ {\n"
			. "        auth_basic off;\n"
			. "        allow all;\n"
			. "        root $webDir;\n"
			. "    }\n";
	}

	/**
	 * Gemeinsamer Teil jedes server-Blocks: Docroot, Logs, Schutz, Standardblöcke,
	 * PHP und zum Schluss der eigene Bereich des Nutzers.
	 */
	private function body(Vhost $v, bool $hsts = true): string
	{
		$root = $this->layout->docroot($v);
		// index.php nur anbieten, wenn PHP auch ausgeliefert wird – sonst würde die
		// Anfrage auf / an der 404-Sperre unten enden statt bei index.html.
		$index = $v->php ? 'index.html index.htm index.php' : 'index.html index.htm';
		$out = "    root $root;\n"
			. "    index $index;\n"
			. "    try_files \$uri \$uri/ =404;\n\n"
			. '    access_log ' . $this->layout->accessLog($v) . ";\n"
			. '    error_log  ' . $this->layout->errorLog($v) . ";\n\n"
			. $this->hardening($v, $hsts) . "\n"
			. $this->compression() . "\n"
			. '    include ' . $this->authSnippetPath($v) . ";\n\n"
			. $this->standardBlocks()
			. "\n" . $this->cacheBlocks() . "\n"
			. $this->phpBlock($v) . "\n";

		// Alles in conf/ gehört dem Nutzer: das Feld "Eigene Direktiven" der Oberfläche
		// schreibt custom.conf, weitere Dateien (z.B. rewrites.conf) werden mit
		// eingebunden. Die Glob-Form duldet auch, dass gar keine Datei existiert.
		$custom = $this->layout->confDir($v) . '/*.conf';
		return $out
			. "    # >>>>\n"
			. "    # ab hier eigene Direktiven (Oberfläche: \"Eigene Direktiven\")\n"
			. "    include $custom;\n"
			. "    # <<<<\n";
	}

	/**
	 * Sicherheitskopfzeilen und Verschweigen der nginx-Version.
	 *
	 * Alle mit "always", damit sie auch bei Fehlerseiten (401 vom Verzeichnisschutz,
	 * 404, 5xx) gesendet werden – ohne "always" liefert nginx sie nur bei 2xx/3xx.
	 *
	 * Bewusst NICHT enthalten: Content-Security-Policy und Permissions-Policy. Beide
	 * lassen sich nicht sinnvoll vorgeben, ohne fremde Seiten zu zerlegen; sie gehören
	 * in die eigenen Direktiven des jeweiligen Hosts.
	 *
	 * "server_tokens off" gehört hierher und nicht in die nginx.conf des Pakets: die
	 * setzt "server_tokens build" und würde bei einem Paketupdate überschrieben. Im
	 * server-Block überschreiben wir den Wert sauber.
	 */
	private function hardening(Vhost $v, bool $hsts): string
	{
		$out = "    server_tokens off;\n"
			// Verhindert, dass der Browser den Inhaltstyp erraet (z.B. eine .txt als
			// Skript ausfuehrt).
			. '    add_header X-Content-Type-Options "nosniff" always;' . "\n"
			// Kein vollstaendiger Verweisender an fremde Ziele.
			. '    add_header Referrer-Policy "strict-origin-when-cross-origin" always;' . "\n"
			// Gegen Clickjacking: Einbetten nur von derselben Herkunft.
			. '    add_header X-Frame-Options "SAMEORIGIN" always;' . "\n";
		if ($v->ssl && $hsts) {
			// 180 Tage, ohne includeSubDomains und ohne preload – bewusst zurückhaltend:
			// Der Browser weigert sich für diese Dauer, die Domain über HTTP zu laden.
			// Wird HTTPS hier später abgeschaltet, bleibt die Seite für wiederkehrende
			// Besucher bis zum Ablauf unerreichbar. Abschaltbar mit "vhost set hsts off".
			$out .= '    add_header Strict-Transport-Security "max-age=15552000" always;' . "\n";
		}
		return $out;
	}

	/**
	 * Komprimierung.
	 *
	 * Ohne "gzip_types" komprimiert nginx ausschliesslich text/html – CSS, JavaScript
	 * und JSON gingen unkomprimiert über die Leitung. text/html steht deshalb absichtlich
	 * nicht in der Liste (es ist immer dabei), schon komprimierte Formate (JPEG, PNG,
	 * WOFF2) ebenso wenig: sie erneut zu packen kostet nur Rechenzeit und macht die
	 * Antwort meist grösser.
	 *
	 * "gzip_static on" liefert eine vorkomprimierte Datei "name.gz" direkt aus, falls
	 * jemand eine anlegt; ohne solche Dateien ist die Direktive wirkungslos.
	 */
	private function compression(): string
	{
		return "    gzip_vary on;\n"
			. "    gzip_proxied any;\n"
			. "    gzip_comp_level 5;\n"
			. "    gzip_min_length 256;\n"
			. "    gzip_static on;\n"
			. "    gzip_types text/plain text/css text/xml text/javascript application/javascript\n"
			. "               application/json application/xml application/rss+xml application/wasm\n"
			. "               image/svg+xml font/ttf font/otf;\n";
	}

	/**
	 * Browser-Cache.
	 *
	 * Gesetzt über "expires", NICHT über add_header: Ein add_header in einem
	 * location-Block verwirft sämtliche geerbten add_header des server-Blocks – die
	 * Sicherheitskopfzeilen wären in genau diesen Blöcken dann weg. "expires" setzt
	 * Cache-Control und Expires ohne diesen Nebeneffekt.
	 *
	 * Bewusst ohne "immutable": Das gilt nur für Dateien, deren Name sich bei jeder
	 * Änderung ändert (Fingerabdruck im Namen). Auf einer gewöhnlichen "style.css"
	 * würde es bedeuten, dass ein Besucher die Änderung wochenlang nicht sieht.
	 *
	 * HTML steht auf "expires -1" (Cache-Control: no-cache): Der Browser fragt jedes
	 * Mal nach, bekommt bei unveränderter Datei aber ein billiges 304 über den ETag.
	 * Das ist der Unterschied zwischen "meine Änderung ist sofort sichtbar" und
	 * "warum sehe ich noch die alte Seite".
	 *
	 * Eigene Direktiven können das überschreiben, aber nicht mit einem weiteren
	 * regulären Ausdruck: Bei denen gewinnt in nginx der erste Treffer, und die
	 * generierten stehen vorher. Wirksam sind ein genauer Pfad ("location = /x.css")
	 * oder ein Präfix mit Vorrang ("location ^~ /assets/") – beide schlagen jeden
	 * regulären Ausdruck, unabhängig von der Reihenfolge.
	 */
	private function cacheBlocks(): string
	{
		return "    location ~* \\.(?:css|js|mjs)\$ {\n"
			. "        expires 7d;\n"
			. "        access_log off;\n"
			. "    }\n\n"
			. "    location ~* \\.(?:jpe?g|png|gif|webp|avif|svg|svgz|ico|cur|woff2?|ttf|otf|eot|mp4|webm|ogv|ogg|mp3|wav|flac|pdf|zip|gz|bz2|xz|7z)\$ {\n"
			. "        expires 30d;\n"
			. "        access_log off;\n"
			. "    }\n\n"
			. "    location ~* \\.(?:html?|json|xml|txt)\$ {\n"
			. "        expires -1;\n"
			. "    }\n";
	}

	/**
	 * Standardblöcke aus der Vorlage: versteckte Dateien sperren (ausser
	 * .well-known), favicon und robots.txt ohne Lograuschen.
	 */
	private function standardBlocks(): string
	{
		return "    location ~ /\\.(?!well-known/) {\n"
			. "        deny all;\n"
			. "        access_log off;\n"
			. "        log_not_found off;\n"
			. "    }\n\n"
			. "    location = /favicon.ico {\n"
			. "        log_not_found off;\n"
			. "        access_log off;\n"
			. "    }\n\n"
			// robots.txt wird bewusst protokolliert: Wer sie abruft und danach einen dort
			// ausgeschlossenen Pfad besucht, verrät sich damit – für einen Honigtopf das
			// eigentliche Signal. Es ist eine Zeile je Besucher, kein Rauschen.
			. "    location = /robots.txt {\n"
			. "        allow all;\n"
			. "        log_not_found off;\n"
			. "    }\n";
	}

	/**
	 * PHP-Weiterleitung an den eigenen FPM-Pool des vHosts.
	 *
	 * Ist PHP aus, wird jede .php-Anfrage mit 404 abgewiesen. Das ist keine Kosmetik:
	 * ohne diese Sperre lieferte nginx die Datei als Text aus, samt allem, was an
	 * Zugangsdaten darin steht.
	 */
	private function phpBlock(Vhost $v): string
	{
		if (!$v->php) {
			return "    location ~ \\.php\$ {\n        return 404;\n    }\n";
		}
		$socket = $this->layout->phpSocket($v);
		return "    location ~ \\.php\$ {\n"
			. "        try_files \$uri =404;\n"
			. '        include ' . $this->config->fastcgiParams . ";\n"
			. "        fastcgi_pass unix:$socket;\n"
			. "        fastcgi_index index.php;\n"
			. "        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;\n"
			. "        fastcgi_intercept_errors on;\n"
			. "        fastcgi_read_timeout 300;\n"
			. "    }\n";
	}
}
