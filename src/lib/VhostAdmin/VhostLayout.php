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
 * erreicht damit weder Snippet noch Zertifikatsverweise noch Logs. Der Basisordner
 * selbst gehört seit dem Abschlussreview vom 2026-09-20 (C2) ausschließlich dem
 * Besitzer (0755, nicht gruppenbeschreibbar) – sonst könnte www-data ihn umbenennen
 * oder Einträge darin ersetzen, auch den root-eigenen conf/-Ordner.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-20 20:38
 */

namespace VhostAdmin;

use VhostAdmin\Value\SnippetScope;

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
	 * Systembenutzer, unter dem PHP dieses vHosts läuft.
	 *
	 * Schema "web<id>" wie bei ISPConfig: kurz (Linux-Benutzernamen sollen 32 Zeichen
	 * nicht überschreiten), stabil über Umbenennungen hinweg und immer gültig – aus
	 * einem Domainnamen abgeleitete Namen wären das nicht (Punkte, Länge, Ziffern am
	 * Anfang). Warum überhaupt ein eigener Benutzer je Host: der gemeinsame Pool läuft
	 * als www-data, und www-data darf per sudoers das vhost-CLI als root aufrufen –
	 * PHP einer Website in diesem Pool wäre also root auf dem Rechner.
	 *
	 * @throws \RuntimeException wenn der vHost noch keine ID hat (nicht gespeichert)
	 */
	public function phpUser(Vhost $vhost): string
	{
		if ($vhost->id === null) {
			throw new \RuntimeException('PHP-Benutzername verlangt einen gespeicherten vHost (keine ID vorhanden).');
		}
		return 'web' . $vhost->id;
	}

	/**
	 * Unix-Socket des eigenen FPM-Pools dieses vHosts.
	 */
	public function phpSocket(Vhost $vhost): string
	{
		return $this->config->fpmSocketDir . '/vhost-' . $vhost->slug() . '.sock';
	}

	/**
	 * Pool-Datei dieses vHosts unter pool.d.
	 */
	public function phpPoolFile(Vhost $vhost): string
	{
		return $this->config->fpmPoolDir . '/vhost-' . $vhost->slug() . '.conf';
	}

	/**
	 * PHP-Fehlerlog dieses vHosts (liegt bei den übrigen Logs).
	 */
	public function phpLog(Vhost $vhost): string
	{
		return $this->logsDir($vhost) . '/php.log';
	}

	/**
	 * Pfadgrenzen für die Prüfung der eigenen Direktiven dieses vHosts.
	 *
	 * Der FPM-Socket wird nur mitgegeben, wenn PHP für den Host an ist – sonst darf
	 * fastcgi_pass auf gar keinen Unix-Socket zeigen.
	 */
	public function snippetScope(Vhost $vhost): SnippetScope
	{
		return new SnippetScope(
			$this->baseDir($vhost),
			$this->confDir($vhost),
			$this->logsDir($vhost),
			$vhost->php ? $this->phpSocket($vhost) : null,
		);
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
		// Mit PHP gehört web/ dem eigenen Benutzer des Hosts: PHP schreibt dort als dieser
		// Benutzer, nginx liest über die Gruppe. www-data verliert das Schreibrecht, das es
		// ohne PHP hat – ohne PHP schreibt dort niemand ausser root, der Modus bleibt aber
		// aus Bestandsgründen gruppenbeschreibbar.
		$webOwner = $vhost->php ? $this->phpUser($vhost) : $owner;
		$webMode = $vhost->php ? 02750 : 02775;
		$specs = [
			// Basisordner gehört ausschließlich dem Besitzer (Gruppe = Besitzer, 0755):
			// www-data braucht hier nur Durchqueren, um nach web/ zu gelangen, nicht
			// Schreibrecht. Wäre er gruppenbeschreibbar, könnte www-data ihn umbenennen
			// oder Einträge ersetzen – auch den root-eigenen conf/-Ordner (C2, Review vom
			// 2026-09-20). Geschrieben wird hier nur von root (Anlegen der Unterordner).
			new DirectorySpec($this->baseDir($vhost), $owner, $owner, 0755, 'Basisordner des vHosts (nicht gruppenbeschreibbar)'),
			new DirectorySpec($this->webDir($vhost), $webOwner, $group, $webMode, 'Docroot (wird ausgeliefert)'),
			new DirectorySpec($this->confDir($vhost), 'root', $group, 0750, 'nginx-Snippet der Oberfläche'),
			new DirectorySpec($this->certDir($vhost), 'root', $owner, 0750, 'Symlinks auf die Zertifikate'),
			new DirectorySpec($this->privateDir($vhost), $owner, $owner, 0750, 'nicht ausgelieferte Dateien'),
			// Mit PHP zusätzlich für andere durchquerbar (0751): PHP läuft als eigener
			// Benutzer und schreibt selbst in logs/php.log – ohne Durchgangsrecht käme es
			// nicht an die Datei. Lesen kann es die übrigen Logs dadurch nicht, die
			// stehen auf 0640 root:<owner>.
			new DirectorySpec($this->logsDir($vhost), 'root', $owner, $vhost->php ? 0751 : 0750, 'Logdateien dieses vHosts'),
		];
		$path = $this->webDir($vhost);
		foreach ($vhost->subdir !== null ? explode('/', $vhost->subdir) : [] as $segment) {
			$path .= '/' . $segment;
			$specs[] = new DirectorySpec($path, $webOwner, $group, $webMode, 'Unterordner des Docroots');
		}
		return $specs;
	}

	/**
	 * Muss dieser vHost auf die neue Struktur gebracht werden?
	 *
	 * Das ist der Fall, wenn der Basisordner existiert und web/ entweder fehlt (die
	 * Dateien liegen noch flach im Basisordner) oder ein Symlink ist. Ein Symlink an
	 * dieser Stelle zählt bewusst als migrationsbedürftig: is_dir() würde ihm sonst
	 * folgen und ihn fälschlich als "schon migriert" durchgehen lassen – der Host
	 * verschwände aus pending(), und die Symlink-Schutzausnahme in der Migration
	 * (ensureDirectory()/moveContentIntoWeb()) würde nie ausgelöst.
	 */
	public function needsMigration(Vhost $vhost): bool
	{
		return is_dir($this->baseDir($vhost))
			&& (is_link($this->webDir($vhost)) || !is_dir($this->webDir($vhost)));
	}
}
