<?php
declare(strict_types=1);

/**
 * Erkennt, ob die Logs eines vHosts während eines Auswertungslaufs rotiert wurden.
 *
 * Die Auswertung läuft stündlich, logrotate einmal täglich zu einer zufälligen Zeit.
 * Treffen beide aufeinander, wird eine Fassung doppelt oder gar nicht gelesen – und ein
 * Vortag, der dabei als abgeschlossen gespeichert würde, bliebe für immer falsch. Der
 * Lauf vergleicht deshalb den Fingerabdruck vor und nach dem Lesen und speichert bei
 * einer Abweichung nichts; die nächste Stunde holt es nach.
 *
 * Der Fingerabdruck besteht aus Name und Inode jeder Logdatei, nicht aus der Grösse:
 * nginx schreibt ständig weiter, das ist keine Rotation. Umbenennen, Neuanlegen und
 * Komprimieren ändern dagegen Namen oder Inodes.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-27 11:40
 */

namespace Honeypot;

final class LogFiles
{
	public static function fingerprint(string $directory): string
	{
		$files = glob($directory . '/{access,error,decoy}.log*', GLOB_BRACE) ?: [];
		$parts = [];
		foreach ($files as $file) {
			// Ohne Cache: stat() merkt sich Ergebnisse innerhalb eines Laufs.
			clearstatcache(true, $file);
			$inode = @fileinode($file);
			$parts[] = basename($file) . ':' . ($inode === false ? '-' : $inode);
		}
		sort($parts);
		return implode(' ', $parts);
	}
}
