<?php
declare(strict_types=1);

/**
 * Eine Gegenstelle, die in den Logdaten sichtbar wird.
 *
 * „Sichtbar" heisst hier ausdrücklich nicht „hat die Verbindung aufgebaut": Vor diesem
 * Rechner sitzt eine Adressumsetzung, die Verbindungsadresse ist nicht zu haben. Was
 * bleibt, sind Stellen, die eine Anfrage selbst nennt – die URL, mit der ein Scanner
 * sich ausweist, das Ziel eines Proxy-Versuchs, der Ablageserver eines Exploits. Die
 * Art (`kind`) sagt, welcher Fall vorliegt; sie zu verschweigen wäre der eigentliche
 * Fehler, denn eine Selbstauskunft kann gelogen sein.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-23 09:30
 */

namespace Honeypot;

final class Peer implements \JsonSerializable
{
	/** Aus der URL, mit der ein Werkzeug sich in seiner Kennung ausweist. */
	public const SELF_DECLARED = 'Selbstauskunft';
	/** Die tatsächliche Verbindungsadresse – nur verfügbar ohne Adressumsetzung davor. */
	public const CONNECTION = 'Verbindung';
	/** Ziel eines CONNECT-Versuchs: Wohin sollte über diesen Rechner gegangen werden? */
	public const PROXY_TARGET = 'Proxy-Ziel';
	/** Stelle, von der eine Anfrage etwas nachladen wollte (Exploit-Ablage). */
	public const PAYLOAD = 'Nachgeladen';

	public function __construct(
		public readonly string $host,
		public readonly string $kind,
		public readonly int $requests,
		public readonly ?string $address = null,
		public readonly ?NetworkInfo $network = null,
		public readonly ?string $reverse = null,
	) {
	}

	/**
	 * Ist der Name schon eine Adresse (dann muss nichts aufgelöst werden)?
	 */
	public function isAddress(): bool
	{
		return filter_var($this->host, FILTER_VALIDATE_IP) !== false;
	}

	/**
	 * Dieselbe Gegenstelle mit nachgetragener Auflösung.
	 */
	public function withResolution(?string $address, ?NetworkInfo $network, ?string $reverse): self
	{
		return new self($this->host, $this->kind, $this->requests, $address, $network, $reverse);
	}

	/**
	 * Dieselbe Gegenstelle mit anderer Anzahl.
	 */
	public function withRequests(int $requests): self
	{
		return new self($this->host, $this->kind, $requests, $this->address, $this->network, $this->reverse);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array
	{
		return [
			'host' => $this->host,
			'kind' => $this->kind,
			'requests' => $this->requests,
			'address' => $this->address,
			'network' => $this->network?->jsonSerialize(),
			'reverse' => $this->reverse,
		];
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function fromArray(array $data): self
	{
		$network = $data['network'] ?? null;
		return new self(
			(string)($data['host'] ?? ''),
			(string)($data['kind'] ?? ''),
			(int)($data['requests'] ?? 0),
			isset($data['address']) && $data['address'] !== null ? (string)$data['address'] : null,
			is_array($network) ? NetworkInfo::fromArray($network) : null,
			isset($data['reverse']) && $data['reverse'] !== null ? (string)$data['reverse'] : null,
		);
	}
}
