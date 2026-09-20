<?php
declare(strict_types=1);

/**
 * Erzeugt die php-fpm-Pool-Datei eines vHosts – ohne Dateizugriff.
 *
 * Jeder vHost bekommt einen eigenen Pool mit eigenem Systembenutzer. Der Grund ist
 * nicht Bequemlichkeit, sondern Notwendigkeit: der mitgelieferte Pool läuft als
 * www-data, und www-data darf per sudoers das vhost-CLI als root aufrufen. PHP einer
 * öffentlichen Website in diesem Pool wäre damit root auf dem Rechner. Mit eigenem
 * Benutzer je Host kommt Website-PHP an diese Rechte nicht heran.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-20 21:55
 */

namespace VhostAdmin\Php;

use VhostAdmin\Vhost;
use VhostAdmin\VhostLayout;

final class PoolRenderer
{
	private const HEADER = "; generiert von vhost – nicht manuell bearbeiten\n";

	public function __construct(private readonly VhostLayout $layout)
	{
	}

	/**
	 * Vollständiger Inhalt der Pool-Datei.
	 */
	public function render(Vhost $vhost): string
	{
		$user = $this->layout->phpUser($vhost);
		$socket = $this->layout->phpSocket($vhost);
		$base = $this->layout->baseDir($vhost);
		$web = $this->layout->webDir($vhost);
		$tmp = $base . '/tmp';

		// open_basedir grenzt PHP auf den eigenen vHost ein: web/ zum Ausliefern,
		// private/ für nicht ausgelieferte Dateien, tmp/ für Uploads. conf/ und logs/
		// gehören root und haben in PHP nichts zu suchen.
		$openBasedir = implode(':', [$web, $base . '/private', $tmp]);

		return self::HEADER
			. '[' . $this->poolName($vhost) . "]\n"
			. "user = $user\n"
			. "group = $user\n"
			// nginx (www-data) muss den Socket öffnen dürfen, sonst nichts.
			. "listen = $socket\n"
			. "listen.owner = www-data\n"
			. "listen.group = www-data\n"
			. "listen.mode = 0660\n"
			// ondemand: ein ruhender vHost belegt keine Prozesse. Bei vielen Hosts auf
			// einem kleinen LXC ist das der sparsamste der drei pm-Modi.
			. "pm = ondemand\n"
			. "pm.max_children = 10\n"
			. "pm.process_idle_timeout = 10s\n"
			. "pm.max_requests = 500\n"
			. "chdir = /\n"
			. "catch_workers_output = yes\n"
			. "php_admin_value[open_basedir] = $openBasedir\n"
			. "php_admin_value[upload_tmp_dir] = $tmp\n"
			. "php_admin_value[sys_temp_dir] = $tmp\n"
			. 'php_admin_value[error_log] = ' . $this->layout->phpLog($vhost) . "\n"
			. "php_admin_flag[log_errors] = on\n"
			// Fehler nie an den Browser: sie gehören ins Log, nicht auf die Webseite.
			. "php_admin_flag[display_errors] = off\n";
	}

	/**
	 * Name des Pools, wie er in eckigen Klammern steht.
	 */
	public function poolName(Vhost $vhost): string
	{
		return 'vhost-' . $vhost->slug();
	}
}
