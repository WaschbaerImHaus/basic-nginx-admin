<?php
declare(strict_types=1);

/**
 * Einstieg der Honigtopf-Ansicht: findet den privaten Ordner des vHosts und lädt die
 * Auswertungsklassen.
 *
 * Die Ansicht läuft in einem php-fpm-Pool mit open_basedir auf web/, private/ und tmp/
 * dieses vHosts – /opt/vhost-admin ist für sie unerreichbar. Die Klassen liegen deshalb
 * als Kopie unter private/honeypot-lib/, die Tagesberichte unter private/honeypot/.
 * Beides legt der Installer dort ab (honeypot/install-dashboard.sh).
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-22 18:20
 */

/**
 * Der private Ordner des vHosts, von hier aus aufwärts gesucht – so darf die Ansicht in
 * jedem Unterordner des Docroots liegen. Leer, wenn nichts gefunden wurde.
 */
function honeypot_private_dir(): string
{
	static $found = null;
	if ($found !== null) {
		return $found;
	}
	$candidate = __DIR__;
	for ($level = 0; $level < 6; $level++) {
		if (is_dir($candidate . '/private/honeypot-lib')) {
			return $found = $candidate . '/private';
		}
		$candidate = dirname($candidate);
	}
	return $found = '';
}

spl_autoload_register(static function (string $class): void {
	$prefix = 'Honeypot\\';
	$private = honeypot_private_dir();
	if ($private === '' || !str_starts_with($class, $prefix)) {
		return;
	}
	$file = $private . '/honeypot-lib/Honeypot/'
		. str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
	if (is_file($file)) {
		require $file;
	}
});
