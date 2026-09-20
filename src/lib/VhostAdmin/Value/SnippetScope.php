<?php
declare(strict_types=1);

/**
 * Bezugsrahmen eines Snippets: die Pfade des vHosts, innerhalb derer es bleiben muss.
 *
 * NginxSnippet prüft pfadgebundene Direktiven (root, alias, include, access_log …)
 * gegen diese Grenzen. Ohne Bezugsrahmen (Scope = null) werden solche Direktiven
 * grundsätzlich abgelehnt – lieber eine Ablehnung als eine ungeprüfte Zulassung.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-20 21:10
 */

namespace VhostAdmin\Value;

final class SnippetScope
{
	/**
	 * @param string  $baseDir   Basisordner des vHosts (/var/www/<domain>)
	 * @param string  $confDir   Ordner der eigenen Direktiven (nur hier darf "include" hinzeigen)
	 * @param string  $logsDir   Logordner (nur hier dürfen access_log/error_log hinzeigen)
	 * @param ?string $phpSocket FPM-Socket dieses vHosts, falls PHP an ist – der einzige
	 *                           erlaubte Unix-Socket für fastcgi_pass und Verwandte
	 */
	public function __construct(
		public readonly string $baseDir,
		public readonly string $confDir,
		public readonly string $logsDir,
		public readonly ?string $phpSocket = null,
	) {
	}

	/**
	 * Liegt $path innerhalb von $dir (oder ist $dir selbst)?
	 *
	 * Vergleicht auf Textebene, aber erst nach Auflösung von "." und ".." – die Datei
	 * muss dabei nicht existieren (realpath() wäre hier unbrauchbar, weil das Snippet
	 * auch auf noch nicht angelegte Dateien zeigen darf). Ein Pfad mit ".." kommt so
	 * nicht aus dem vHost heraus.
	 */
	public static function isInside(string $path, string $dir): bool
	{
		$normalized = self::normalize($path);
		$boundary = self::normalize($dir);
		return $normalized === $boundary || str_starts_with($normalized, $boundary . '/');
	}

	/**
	 * Löst "." und ".." rein rechnerisch auf und entfernt doppelte Schrägstriche.
	 */
	public static function normalize(string $path): string
	{
		$isAbsolute = str_starts_with($path, '/');
		$out = [];
		foreach (explode('/', $path) as $segment) {
			if ($segment === '' || $segment === '.') {
				continue;
			}
			if ($segment === '..') {
				// Über die Wurzel hinaus führt nichts; bei relativen Pfaden bleibt ".."
				// stehen, damit ein solcher Pfad nie versehentlich als "innerhalb" gilt.
				if ($out !== [] && end($out) !== '..') {
					array_pop($out);
				} elseif (!$isAbsolute) {
					$out[] = '..';
				}
				continue;
			}
			$out[] = $segment;
		}
		return ($isAbsolute ? '/' : '') . implode('/', $out);
	}
}
