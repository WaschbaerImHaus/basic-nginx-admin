<?php
declare(strict_types=1);

/**
 * Die Schutzbereiche eines vHosts, abgeleitet aus seinen Benutzern (seit 2026-09-27:
 * Pfad je Benutzer statt je Domain).
 *
 * Regeln:
 *  - Jeder Benutzer hat einen Pfad oder keinen (= ganze Seite).
 *  - Geschützt ist jeder Pfad, den ein Benutzer hat, samt allem darunter. Die ganze
 *    Seite ist geschützt, sobald ein Benutzer ohne Pfad existiert – oder gar keiner:
 *    Ein neuer Host startet gesperrt.
 *  - In einen Bereich darf, wessen Pfad ihn abdeckt: Benutzer ohne Pfad überall,
 *    „/admin" auch in „/admin/intern", aber nicht in „/administrator" oder „/shop".
 *
 * Die Reihenfolge ist die für nginx: genauere Pfade zuerst (eine map nimmt den ersten
 * passenden Ausdruck), die ganze Seite zuletzt.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-27 14:30
 */

namespace VhostAdmin\Auth;

final class AccessAreas
{
	/** @param list<AccessArea> $areas */
	private function __construct(private readonly array $areas)
	{
	}

	/**
	 * @param list<array{username: string, hash: string, path: ?string}> $users
	 */
	public static function fromUsers(array $users): self
	{
		$paths = [];
		$wholeSite = $users === [];
		foreach ($users as $user) {
			if ($user['path'] === null) {
				$wholeSite = true;
			} else {
				$paths[$user['path']] = true;
			}
		}
		$paths = array_keys($paths);
		// Genauere zuerst: Ein Unterpfad ist immer länger als sein Elternpfad.
		usort($paths, static fn(string $a, string $b): int => strlen($b) <=> strlen($a) ?: strcmp($a, $b));

		$areas = [];
		foreach ($paths as $path) {
			$areas[] = new AccessArea($path, array_values(array_filter(
				$users,
				static fn(array $user): bool => self::covers($user['path'], $path)
			)));
		}
		if ($wholeSite) {
			$areas[] = new AccessArea(null, array_values(array_filter(
				$users,
				static fn(array $user): bool => $user['path'] === null
			)));
		}
		return new self($areas);
	}

	/** @return list<AccessArea> */
	public function areas(): array
	{
		return $this->areas;
	}

	/** Ist die ganze Seite geschützt (und nicht nur einzelne Pfade)? */
	public function coversWholeSite(): bool
	{
		$last = $this->areas[array_key_last($this->areas) ?? 0] ?? null;
		return $last !== null && $last->path === null;
	}

	/** Deckt der Pfad eines Benutzers ($own, null = ganze Seite) den Pfad $path ab? */
	private static function covers(?string $own, string $path): bool
	{
		return $own === null || $own === $path || str_starts_with($path, $own . '/');
	}
}
