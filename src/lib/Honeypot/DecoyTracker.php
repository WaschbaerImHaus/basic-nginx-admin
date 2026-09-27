<?php
declare(strict_types=1);

/**
 * Köderkennungen: welche ausgegeben wurden und wo sie später wieder auftauchen.
 *
 * Ausgegeben: nginx schreibt jede Köderauslieferung mit ihrer Kennung als JSON-Zeile nach
 * logs/decoy.log (Format siehe Decoys::httpConfig). Benutzt: Die Kennung steht in einer
 * späteren Anfrage (etwa als Schlüssel der internen Adresse aus dem Köder) oder als
 * Benutzername eines Anmeldeversuchs („deploy-<kennung>").
 *
 * Nur bereits ausgegebene Kennungen zählen – zehn Hexziffern kommen im Log auch zufällig
 * vor (Sitzungsnummern, Prüfsummen).
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-27 11:30
 */

namespace Honeypot;

final class DecoyTracker
{
	/**
	 * Die ausgegebenen Kennungen aus decoy.log.
	 *
	 * @param list<string> $lines
	 * @return list<array{token: string, date: string, time: string, request: string, agent: string}>
	 */
	public static function issued(array $lines): array
	{
		$out = [];
		foreach ($lines as $line) {
			$data = json_decode($line, true);
			// Nur ausgelieferte Köder, und keine Selbsttests vom Rechner selbst.
			if (!is_array($data) || ($data['status'] ?? '') !== '200' || LogParser::isLocal((string)($data['ip'] ?? ''))) {
				continue;
			}
			$token = (string)($data['token'] ?? '');
			$stamp = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, (string)($data['time'] ?? ''));
			if (Decoys::tokensIn($token) !== [$token] || $stamp === false) {
				continue;
			}
			$out[] = [
				'token' => $token,
				'date' => $stamp->format('Y-m-d'),
				'time' => $stamp->format('H:i:s'),
				'request' => substr((string)($data['request'] ?? ''), 0, 200),
				'agent' => substr((string)($data['agent'] ?? ''), 0, 200),
			];
		}
		return $out;
	}

	/**
	 * Wo bekannte Kennungen wieder auftauchen.
	 *
	 * @param list<LogEntry>      $entries    Einträge aus dem access.log
	 * @param list<string>        $errorLines Zeilen aus dem error.log
	 * @param array<string, true> $known      ausgegebene Kennungen
	 * @return list<array{token: string, date: string, time: string, source: string, detail: string, agent: string}>
	 */
	public static function uses(array $entries, array $errorLines, array $known): array
	{
		$out = [];
		if ($known === []) {
			return $out;
		}
		foreach ($entries as $entry) {
			foreach (Decoys::tokensIn($entry->request) as $token) {
				if (isset($known[$token])) {
					$out[] = [
						'token' => $token, 'date' => $entry->date, 'time' => $entry->time, 'source' => 'Anfrage',
						'detail' => substr($entry->request, 0, 200), 'agent' => substr($entry->agent, 0, 200),
					];
				}
			}
		}
		foreach ($errorLines as $line) {
			if (preg_match('~^(\d{4})/(\d{2})/(\d{2}) (\d{2}:\d{2}:\d{2}) .*?user "([^"]*)" (?:was not found|password mismatch)~', $line, $m) !== 1
				|| preg_match('~, client: (?:127\.0\.0\.1|::1),~', $line) === 1) {
				continue;
			}
			foreach (Decoys::tokensIn($m[5]) as $token) {
				if (isset($known[$token])) {
					$out[] = [
						'token' => $token, 'date' => "$m[1]-$m[2]-$m[3]", 'time' => $m[4], 'source' => 'Anmeldung',
						'detail' => substr($m[5], 0, 200), 'agent' => '',
					];
				}
			}
		}
		return $out;
	}
}
