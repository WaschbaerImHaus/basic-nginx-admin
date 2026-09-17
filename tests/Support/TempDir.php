<?php
declare(strict_types=1);

/**
 * Hilfsklasse: temporäre Verzeichnisse für Tests anlegen und rekursiv löschen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 10:23
 */

namespace Tests\Support;

final class TempDir
{
	/**
	 * Legt ein eindeutiges Temp-Verzeichnis an und gibt den Pfad zurück.
	 */
	public static function create(): string
	{
		$dir = sys_get_temp_dir() . '/vhost-admin-test-' . bin2hex(random_bytes(6));
		mkdir($dir, 0700, true);
		return $dir;
	}

	/**
	 * Löscht ein Verzeichnis samt Inhalt. Symlinks werden entfernt, nicht verfolgt.
	 */
	public static function remove(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($items as $item) {
			$item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
		}
		rmdir($dir);
	}
}
