<?php
declare(strict_types=1);

/**
 * Autoloader für den Namespace VhostAdmin.
 *
 * Eine Klasse VhostAdmin\Foo\Bar liegt in lib/VhostAdmin/Foo/Bar.php.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:23
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
