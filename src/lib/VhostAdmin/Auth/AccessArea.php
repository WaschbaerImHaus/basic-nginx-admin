<?php
declare(strict_types=1);

/**
 * Ein geschützter Bereich: ein Pfad (oder die ganze Seite) und die Benutzer, die dort
 * hinein dürfen. Jeder Bereich bekommt eine eigene htpasswd-Datei.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-27 14:30
 */

namespace VhostAdmin\Auth;

final class AccessArea
{
	/**
	 * @param ?string $path  geprüfter Pfad (siehe Value\ProtectPath), null = ganze Seite
	 * @param list<array{username: string, hash: string, path: ?string}> $users Zugelassene
	 */
	public function __construct(public readonly ?string $path, public readonly array $users)
	{
	}

	/**
	 * Zusatz zum Namen der htpasswd-Datei. Die ganze Seite behält den bisherigen Namen
	 * <slug>.htpasswd; ein Pfad bekommt einen festen Zusatz aus seinem Hash – derselbe
	 * Pfad ergibt bei jedem Rendern dieselbe Datei, und der Pfad selbst taucht nicht im
	 * Dateinamen auf (Schrägstriche, Punkte).
	 */
	public function fileSuffix(): string
	{
		return $this->path === null ? '' : '.p-' . substr(sha1($this->path), 0, 10);
	}
}
