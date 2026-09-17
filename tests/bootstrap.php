<?php
declare(strict_types=1);

/**
 * Test-Bootstrap: lädt den Autoloader des Projekts und die Test-Hilfsklassen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:23
 */

require dirname(__DIR__) . '/src/bootstrap.php';

spl_autoload_register(static function (string $class): void {
	$prefix = 'Tests\\';
	if (!str_starts_with($class, $prefix)) {
		return;
	}
	$file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
	if (is_file($file)) {
		require $file;
	}
});
