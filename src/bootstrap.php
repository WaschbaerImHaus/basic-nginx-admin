<?php
declare(strict_types=1);

/**
 * Autoloader für die Namespaces des Projekts.
 *
 * Eine Klasse VhostAdmin\Foo\Bar liegt in lib/VhostAdmin/Foo/Bar.php, eine Klasse
 * Honeypot\Bar in lib/Honeypot/Bar.php. Honigtopf-Auswertung und Verwaltung teilen
 * sich nichts ausser dem Autoloader – die Auswertung läuft als eigener Dienst und darf
 * nicht von der Datenbank der Verwaltung abhängen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-22 17:30
 */

spl_autoload_register(static function (string $class): void {
	$prefix = 'VhostAdmin\\';
	if (!str_starts_with($class, $prefix)) {
		return;
	}
	$file = __DIR__ . '/lib/VhostAdmin/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
	if (is_file($file)) {
		require $file;
	}
});

spl_autoload_register(static function (string $class): void {
	$prefix = 'Honeypot\\';
	if (!str_starts_with($class, $prefix)) {
		return;
	}
	$file = __DIR__ . '/lib/Honeypot/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
	if (is_file($file)) {
		require $file;
	}
});
