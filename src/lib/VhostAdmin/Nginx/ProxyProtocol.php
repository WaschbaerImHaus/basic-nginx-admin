<?php
declare(strict_types=1);

/**
 * Einstellung PROXY-Protokoll: zusätzliche Ports für einen vorgeschalteten Tunnel.
 *
 * Der öffentliche Verkehr kommt über einen Tunnelserver mit Adressumsetzung; ohne
 * Hilfe sieht nginx jede Anfrage von der Tunneladresse. Schickt der Tunnelserver
 * (nginx "stream" mit "proxy_protocol on") eine PROXY-Zeile voraus, kennt nginx die
 * echte Client-Adresse. Ein Port mit "proxy_protocol" lehnt aber jede Verbindung
 * ohne diese Zeile ab – deshalb bleiben 80/443 unverändert, und jeder öffentliche
 * Host lauscht ZUSÄTZLICH auf zwei eigenen Ports (Standard 8081/8444).
 *
 * Die http-Ebene bekommt genau eine Datei (realip.conf): Nur den eingetragenen
 * Tunneladressen wird die Client-Adresse geglaubt, und eine map übersetzt den
 * internen Port zurück in den öffentlichen (für PHP: SERVER_PORT).
 *
 * Nur IPv4/IPv6 des Tunnels; QUIC (UDP) kann kein PROXY-Protokoll und bleibt außen vor.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-10-16 15:50
 */

namespace VhostAdmin\Nginx;

use VhostAdmin\Value\Cidr;

final class ProxyProtocol
{
	/** Kopf der erzeugten Datei; nur Dateien mit diesem Kopf gehören vhost-admin. */
	public const HEADER = "# generiert von vhost – nicht manuell bearbeiten\n";

	public const DEFAULT_HTTP_PORT = 8081;
	public const DEFAULT_HTTPS_PORT = 8444;

	/**
	 * @param bool       $enabled   ob die zusätzlichen Ports geschrieben werden
	 * @param list<Cidr> $trusted   Absender, deren PROXY-Zeile geglaubt wird (Tunnel)
	 * @param int        $httpPort  zusätzlicher Port für HTTP
	 * @param int        $httpsPort zusätzlicher Port für HTTPS
	 */
	private function __construct(
		public readonly bool $enabled,
		public readonly array $trusted,
		public readonly int $httpPort,
		public readonly int $httpsPort,
	) {
	}

	/**
	 * Aus den gespeicherten Einstellungen. Fehlt etwas oder ist etwas ungültig
	 * (z. B. von Hand in der Datenbank geändert), gilt "aus" – eine halbe
	 * Konfiguration würde nginx am Laden hindern.
	 *
	 * @param ?string $state "on" oder "off"
	 * @param ?string $from  Tunneladressen, durch Komma getrennt
	 * @param ?string $ports "<http>,<https>"
	 */
	public static function fromSettings(?string $state, ?string $from, ?string $ports): self
	{
		$off = new self(false, [], self::DEFAULT_HTTP_PORT, self::DEFAULT_HTTPS_PORT);
		try {
			$trusted = [];
			foreach (array_filter(explode(',', (string)$from), static fn (string $s): bool => trim($s) !== '') as $raw) {
				$trusted[] = Cidr::fromString($raw);
			}
			[$http, $https] = [self::DEFAULT_HTTP_PORT, self::DEFAULT_HTTPS_PORT];
			if ($ports !== null && $ports !== '') {
				$parts = explode(',', $ports);
				if (count($parts) !== 2 || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
					return $off;
				}
				[$http, $https] = [(int)$parts[0], (int)$parts[1]];
			}
			$stored = new self(false, $trusted, $http, $https);
			return $state === 'on' ? $stored->enable() : $stored;
		} catch (\InvalidArgumentException) {
			return $off;
		}
	}

	/**
	 * Eingeschaltete Fassung. Ohne Angaben gelten die bisherigen Adressen und Ports.
	 *
	 * @param ?list<Cidr> $trusted   Tunneladressen (null = bisherige)
	 * @param ?int        $httpPort  null = bisheriger
	 * @param ?int        $httpsPort null = bisheriger
	 * @param int         $adminPort Port der Oberfläche (reserviert)
	 * @throws \InvalidArgumentException ohne Tunneladresse oder bei ungültigen Ports
	 */
	public function enable(?array $trusted = null, ?int $httpPort = null, ?int $httpsPort = null, int $adminPort = 8080): self
	{
		$trusted ??= $this->trusted;
		$httpPort ??= $this->httpPort;
		$httpsPort ??= $this->httpsPort;
		if ($trusted === []) {
			throw new \InvalidArgumentException('Tunneladresse fehlt: vhost set proxy_protocol on <ip[,ip]> [http-port https-port]');
		}
		foreach ([$httpPort, $httpsPort] as $port) {
			if ($port < 1 || $port > 65535) {
				throw new \InvalidArgumentException("Ungültiger Port: $port");
			}
			if (in_array($port, [80, 443, $adminPort], true)) {
				throw new \InvalidArgumentException("Port $port ist reserviert");
			}
		}
		if ($httpPort === $httpsPort) {
			throw new \InvalidArgumentException('HTTP- und HTTPS-Port müssen verschieden sein');
		}
		return new self(true, array_values($trusted), $httpPort, $httpsPort);
	}

	/**
	 * Ausgeschaltete Fassung; Adressen und Ports bleiben für ein späteres "on" gespeichert.
	 */
	public function disable(): self
	{
		return new self(false, $this->trusted, $this->httpPort, $this->httpsPort);
	}

	/**
	 * Werte für die Tabelle settings (leere Adressliste = null, löscht den Eintrag).
	 *
	 * @return array{proxy_protocol: string, proxy_from: ?string, proxy_ports: string}
	 */
	public function settings(): array
	{
		$from = implode(',', array_map('strval', $this->trusted));
		return [
			'proxy_protocol' => $this->enabled ? 'on' : 'off',
			'proxy_from' => $from === '' ? null : $from,
			'proxy_ports' => $this->httpPort . ',' . $this->httpsPort,
		];
	}

	/**
	 * Ist der Port eingeschaltet als PROXY-Port belegt? (Dann darf ihn kein localhost-Host nehmen.)
	 */
	public function usesPort(int $port): bool
	{
		return $this->enabled && in_array($port, [$this->httpPort, $this->httpsPort], true);
	}

	/**
	 * Zusätzliche Zeilen für einen öffentlichen :80-Block.
	 *
	 * "port_in_redirect off": Leitet nginx selbst um (z. B. /ordner → /ordner/), schriebe
	 * es sonst den internen Port in die Adresse – der Besucher hat aber 80/443 aufgerufen.
	 */
	public function listenHttp(): string
	{
		return $this->enabled ? "    listen {$this->httpPort} proxy_protocol;\n    port_in_redirect off;\n" : '';
	}

	/**
	 * Zusätzliche Zeilen für einen öffentlichen 443-Block (TLS über den Tunnel, Zertifikat bleibt hier).
	 */
	public function listenHttps(): string
	{
		return $this->enabled ? "    listen {$this->httpsPort} ssl proxy_protocol;\n    port_in_redirect off;\n" : '';
	}

	/**
	 * Zeile für den PHP-Block: php-fpm nimmt bei doppelten Parametern den letzten, diese
	 * Zeile steht deshalb nach dem include von fastcgi_params.
	 */
	public function fastcgiPort(): string
	{
		return $this->enabled ? "        fastcgi_param SERVER_PORT \$vhostadmin_public_port;\n" : '';
	}

	/**
	 * Inhalt der http-Datei (realip.conf) oder null, wenn ausgeschaltet.
	 */
	public function httpConfig(): ?string
	{
		if (!$this->enabled) {
			return null;
		}
		$out = self::HEADER
			. "# PROXY-Protokoll (vhost set proxy_protocol): Nur diesen Absendern wird die\n"
			. "# mitgeschickte Client-Adresse geglaubt; \$remote_addr ist danach die echte Adresse.\n";
		foreach ($this->trusted as $cidr) {
			$out .= "set_real_ip_from $cidr;\n";
		}
		return $out . "real_ip_header proxy_protocol;\n"
			. "\n"
			. "# Öffentlicher Port für PHP (SERVER_PORT): Wer über {$this->httpPort}/{$this->httpsPort} kommt, hat 80/443 aufgerufen.\n"
			. "map \$server_port \$vhostadmin_public_port {\n"
			. "    {$this->httpPort} 80;\n"
			. "    {$this->httpsPort} 443;\n"
			. "    default \$server_port;\n"
			. "}\n";
	}

	/**
	 * Stammt der Text aus dieser Klasse? Nur dann darf vhost-admin die Datei entfernen.
	 */
	public static function isGenerated(string $text): bool
	{
		return str_starts_with($text, self::HEADER);
	}
}
