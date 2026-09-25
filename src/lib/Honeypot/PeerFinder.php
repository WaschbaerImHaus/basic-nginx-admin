<?php
declare(strict_types=1);

/**
 * Sucht in den Logeinträgen nach Gegenstellen.
 *
 * Reihenfolge der Quellen ist Absicht: Die Verbindungsadresse ist die beste Auskunft,
 * wenn es sie gibt. Sitzt eine Adressumsetzung davor – hier der Fall –, bleiben nur
 * Stellen, die eine Anfrage selbst nennt. Die sind schwächer (eine Selbstauskunft kann
 * gelogen sein), aber sie sind echt und nachprüfbar, während jede Schätzung aus der
 * Gateway-Adresse frei erfunden wäre.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-23 09:30
 */

namespace Honeypot;

final class PeerFinder
{
	// Ohne \b am Anfang: In "wget%20http://..." steht vor dem h eine Ziffer, es gäbe
	// also keine Wortgrenze – und genau so kommen Exploit-Versuche daher.
	/** URLs in Kennungen und Anfragen. */
	private const URL = '~https?://([a-z0-9._-]+\.[a-z]{2,63}|\d{1,3}(?:\.\d{1,3}){3}|\[[0-9a-f:]+\])~i';
	/** Ziel eines CONNECT: host:port. */
	private const CONNECT_TARGET = '~^([a-z0-9._-]+\.[a-z]{2,63}|\d{1,3}(?:\.\d{1,3}){3}|\[[0-9a-f:]+\])(?::\d+)?$~i';

	/**
	 * @param list<string> $ownNames eigene Namen und Adressen; sie sind keine Gegenstelle
	 */
	public function __construct(private readonly array $ownNames = [])
	{
	}

	/**
	 * @param list<LogEntry> $entries
	 * @return list<Peer> häufigste zuerst
	 */
	public function find(array $entries): array
	{
		/** @var array<string, array{kind: string, count: int}> $peers */
		$peers = [];
		$add = static function (?string $host, string $kind) use (&$peers): void {
			if ($host === null) {
				return;
			}
			// Dieselbe Stelle kann auf mehreren Wegen auftauchen. Dann zählt sie einmal;
			// die zuerst gefundene Art gewinnt (Verbindung vor Selbstauskunft, siehe
			// Reihenfolge in der Schleife).
			if (isset($peers[$host])) {
				$peers[$host]['count']++;
				return;
			}
			$peers[$host] = ['kind' => $kind, 'count' => 1];
		};

		foreach ($entries as $entry) {
			// 1. Die Verbindungsadresse – aber nur, wenn sie überhaupt etwas aussagt.
			//    Private und reservierte Adressen sind das Gateway, nicht die Gegenstelle.
			if (filter_var($entry->ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
				$add($this->accept($entry->ip), Peer::CONNECTION);
				continue;
			}

			// 2. Ziel eines Proxy-Versuchs.
			if ($entry->method === 'CONNECT' && preg_match(self::CONNECT_TARGET, $entry->path, $m) === 1) {
				$add($this->accept($m[1]), Peer::PROXY_TARGET);
				continue;
			}

			// 3. Was die Anfrage nachladen wollte (Exploit-Ablage). Einmal dekodiert,
			//    weil solche Adressen fast immer prozentkodiert ankommen.
			if (preg_match(self::URL, rawurldecode($entry->request), $m) === 1) {
				$add($this->accept($m[1]), Peer::PAYLOAD);
				continue;
			}

			// 4. Die URL, mit der das Werkzeug sich ausweist. Nur aus URLs, nie aus
			//    nackten Zahlenfolgen: "Chrome/108.0.0.0" wäre sonst eine Adresse.
			if (preg_match(self::URL, $entry->agent, $m) === 1) {
				$add($this->accept($m[1]), Peer::SELF_DECLARED);
			}
		}

		$list = [];
		foreach ($peers as $host => $data) {
			$list[] = new Peer($host, $data['kind'], $data['count']);
		}
		usort($list, static fn(Peer $a, Peer $b): int => $b->requests <=> $a->requests ?: strcmp($a->host, $b->host));
		return $list;
	}

	/**
	 * Normalisiert den Namen und weist eigene Namen ab.
	 */
	private function accept(string $host): ?string
	{
		$host = strtolower(trim($host, '[]. '));
		if ($host === '' || in_array($host, array_map('strtolower', $this->ownNames), true)) {
			return null;
		}
		return $host;
	}
}
