<?php
declare(strict_types=1);

/**
 * Fehlgeschlagene Anmeldungen aus dem error.log.
 *
 * Die versuchten Namen sind aufschlussreicher als ihre Zahl: „admin" und „root" sind
 * geraten, der Domainname ist gezielt.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-22 17:20
 */

namespace Honeypot;

final class LoginAttempts
{
	/**
	 * @param list<string> $lines Zeilen aus error.log
	 * @return array<string, int> Benutzername => Versuche, absteigend
	 */
	public function fromLines(array $lines): array
	{
		$users = [];
		foreach ($lines as $line) {
			if (preg_match('/user "([^"]*)" (?:was not found|password mismatch)/', $line, $m) === 1) {
				$users[$m[1]] = ($users[$m[1]] ?? 0) + 1;
			}
		}
		arsort($users);
		return $users;
	}

	/**
	 * Dasselbe, aber nach Kalendertag getrennt. nginx schreibt ins error.log ein
	 * Datum im Format "2026/09/21 15:55:33"; Zeilen ohne Datum zählen nicht mit.
	 *
	 * @param list<string> $lines Zeilen aus error.log
	 * @return array<string, array<string, int>> Datum (Y-m-d) => Benutzername => Versuche
	 */
	public function byDate(array $lines): array
	{
		$byDate = [];
		foreach ($lines as $line) {
			if (preg_match('/^(\d{4})\/(\d{2})\/(\d{2}) /', $line, $d) !== 1) {
				continue;
			}
			$byDate["$d[1]-$d[2]-$d[3]"][] = $line;
		}
		$out = [];
		foreach ($byDate as $date => $group) {
			$users = $this->fromLines($group);
			if ($users !== []) {
				$out[$date] = $users;
			}
		}
		ksort($out);
		return $out;
	}
}
