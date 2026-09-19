<?php
declare(strict_types=1);

/**
 * Pfade und Soll-Rechte eines vHosts.
 *
 * Einzige Quelle für das Verzeichnis-Layout: /var/www/<slug>/ enthält web/ (Docroot),
 * conf/ (von der Oberfläche gepflegtes nginx-Snippet), cert/ (Symlinks auf die
 * Zertifikate), private/ (nicht ausgeliefert) und logs/ (Zugriffs- und Fehlerlog).
 *
 * www-data darf ausschließlich in web/ schreiben; ein dort untergeschobener Symlink
 * erreicht damit weder Snippet noch Zertifikatsverweise noch Logs.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-19 09:14
 */

namespace VhostAdmin;

final class VhostLayout
{
	/**
	 * Übernimmt die Konfiguration, aus der Web-Wurzel und Soll-Besitzer stammen.
	 */
	public function __construct(private readonly Config $config)
	{
	}

	/**
	 * Basisordner des vHosts unterhalb der Web-Wurzel.
	 */
	public function baseDir(Vhost $vhost): string
	{
		return $this->config->wwwRoot . '/' . $vhost->slug();
	}

	/**
	 * Ordner, aus dem ausgeliefert wird (ohne optionalen Unterordner).
	 */
	public function webDir(Vhost $vhost): string
	{
		return $this->baseDir($vhost) . '/web';
	}

	/**
	 * Tatsächlicher Docroot: web/ plus optionalen Unterordner.
	 */
	public function docroot(Vhost $vhost): string
	{
		return $this->webDir($vhost) . ($vhost->subdir !== null ? '/' . $vhost->subdir : '');
	}

	/**
	 * Ordner des von der Oberfläche gepflegten nginx-Snippets.
	 */
	public function confDir(Vhost $vhost): string
	{
		return $this->baseDir($vhost) . '/conf';
	}

	/**
	 * Datei des nginx-Snippets.
	 */
	public function confFile(Vhost $vhost): string
	{
		return $this->confDir($vhost) . '/custom.conf';
	}

	/**
	 * Ordner mit den Symlinks auf die Let's-Encrypt-Zertifikate.
	 */
	public function certDir(Vhost $vhost): string
	{
		return $this->baseDir($vhost) . '/cert';
	}

	/**
	 * Ordner für Dateien, die nicht ausgeliefert werden sollen.
	 */
	public function privateDir(Vhost $vhost): string
	{
		return $this->baseDir($vhost) . '/private';
	}

	/**
	 * Ordner der nginx-Logdateien dieses vHosts.
	 */
	public function logsDir(Vhost $vhost): string
	{
		return $this->baseDir($vhost) . '/logs';
	}

	/**
	 * Zugriffslog dieses vHosts.
	 */
	public function accessLog(Vhost $vhost): string
	{
		return $this->logsDir($vhost) . '/access.log';
	}

	/**
	 * Fehlerlog dieses vHosts.
	 */
	public function errorLog(Vhost $vhost): string
	{
		return $this->logsDir($vhost) . '/error.log';
	}

	/**
	 * Alle anzulegenden Verzeichnisse mit ihren Soll-Rechten, Eltern vor Kindern.
	 *
	 * @return list<DirectorySpec>
	 */
	public function directories(Vhost $vhost): array
	{
		$owner = $this->config->wwwOwner;
		$group = $this->config->wwwGroup;
		$specs = [
			new DirectorySpec($this->baseDir($vhost), $owner, $group, 02775, 'Basisordner des vHosts'),
			new DirectorySpec($this->webDir($vhost), $owner, $group, 02775, 'Docroot (wird ausgeliefert)'),
			new DirectorySpec($this->confDir($vhost), 'root', $group, 0750, 'nginx-Snippet der Oberfläche'),
			new DirectorySpec($this->certDir($vhost), 'root', $owner, 0750, 'Symlinks auf die Zertifikate'),
			new DirectorySpec($this->privateDir($vhost), $owner, $owner, 0750, 'nicht ausgelieferte Dateien'),
			new DirectorySpec($this->logsDir($vhost), 'root', $owner, 0750, 'Logdateien dieses vHosts'),
		];
		$path = $this->webDir($vhost);
		foreach ($vhost->subdir !== null ? explode('/', $vhost->subdir) : [] as $segment) {
			$path .= '/' . $segment;
			$specs[] = new DirectorySpec($path, $owner, $group, 02775, 'Unterordner des Docroots');
		}
		return $specs;
	}

	/**
	 * Muss dieser vHost auf die neue Struktur gebracht werden?
	 *
	 * Das ist genau dann der Fall, wenn der Basisordner existiert, aber noch kein web/
	 * enthält – dann liegen die Dateien noch flach im Basisordner.
	 */
	public function needsMigration(Vhost $vhost): bool
	{
		return is_dir($this->baseDir($vhost)) && !is_dir($this->webDir($vhost));
	}
}
