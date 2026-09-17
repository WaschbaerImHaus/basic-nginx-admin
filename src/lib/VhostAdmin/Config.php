<?php
declare(strict_types=1);

/**
 * Konfiguration: alle Pfade und Ports der Installation.
 *
 * Die Standardwerte entsprechen der Installation durch install.sh. Tests
 * erzeugen per fromArray() eine Konfiguration mit temporären Verzeichnissen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:35
 */

namespace VhostAdmin;

final class Config
{
	/**
	 * @param string $dbPath          SQLite-Datei mit vHosts, Benutzern, IPs und Einstellungen
	 * @param string $wwwRoot         Wurzel der Docroots (/var/www)
	 * @param string $wwwOwner        Besitzer neuer Docroots (wird von install.sh gesetzt)
	 * @param string $wwwGroup        Gruppe neuer Docroots (nginx-Worker müssen lesen können)
	 * @param string $sitesAvailable  nginx sites-available
	 * @param string $sitesEnabled    nginx sites-enabled
	 * @param string $authDir         htpasswd-Dateien und Auth-Snippets
	 * @param string $letsEncryptLive Zertifikatsverzeichnis von certbot
	 * @param string $vhostBinary     Pfad des CLI, das die Oberfläche per sudo aufruft
	 * @param string $templatePath    Vorlage der bunten Startseite
	 * @param int    $adminPort       Port der Verwaltungsoberfläche (reserviert)
	 * @param bool   $ipv6            ob nginx zusätzlich auf IPv6 lauschen soll
	 */
	public function __construct(
		public readonly string $dbPath,
		public readonly string $wwwRoot,
		public readonly string $wwwOwner,
		public readonly string $wwwGroup,
		public readonly string $sitesAvailable,
		public readonly string $sitesEnabled,
		public readonly string $authDir,
		public readonly string $letsEncryptLive,
		public readonly string $vhostBinary,
		public readonly string $templatePath,
		public readonly int $adminPort,
		public readonly bool $ipv6,
	) {
	}

	/**
	 * Konfiguration der Standardinstallation.
	 */
	public static function defaults(): self
	{
		return self::fromArray([]);
	}

	/**
	 * Konfiguration aus einem Array; fehlende Schlüssel erhalten Standardwerte.
	 *
	 * @param array<string, mixed> $values Schlüssel = Eigenschaftsnamen
	 * @throws \InvalidArgumentException bei unbekannten Schlüsseln
	 */
	public static function fromArray(array $values): self
	{
		$defaults = [
			'dbPath' => '/var/lib/vhost-admin/vhosts.sqlite',
			'wwwRoot' => '/var/www',
			'wwwOwner' => 'user',
			'wwwGroup' => 'www-data',
			'sitesAvailable' => '/etc/nginx/sites-available',
			'sitesEnabled' => '/etc/nginx/sites-enabled',
			'authDir' => '/etc/nginx/auth',
			'letsEncryptLive' => '/etc/letsencrypt/live',
			'vhostBinary' => '/usr/local/sbin/vhost',
			'templatePath' => dirname(__DIR__, 2) . '/templates/index.html',
			'adminPort' => 8080,
			'ipv6' => self::systemHasIpv6(),
		];
		$unknown = array_diff_key($values, $defaults);
		if ($unknown !== []) {
			throw new \InvalidArgumentException('Unbekannte Konfigurationsschlüssel: ' . implode(', ', array_keys($unknown)));
		}
		return new self(...array_merge($defaults, $values));
	}

	/**
	 * Prüft, ob das System IPv6-Adressen hat (dann lauscht nginx auch auf [::]).
	 */
	public static function systemHasIpv6(): bool
	{
		return is_file('/proc/net/if_inet6') && trim((string)file_get_contents('/proc/net/if_inet6')) !== '';
	}
}
