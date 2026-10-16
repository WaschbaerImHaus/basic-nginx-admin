<?php
declare(strict_types=1);

/**
 * Autoloader für `VhostAdmin\`. Der Honigtopf ist seit 2026-10-15 ein eigenes Projekt
 * (Bitbucket von-null/honeypot) und benutzt die Verwaltung nur über das CLI `vhost`.
 *
 * Eine Klasse VhostAdmin\Foo\Bar liegt in lib/VhostAdmin/Foo/Bar.php.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-10-16 09:03
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
