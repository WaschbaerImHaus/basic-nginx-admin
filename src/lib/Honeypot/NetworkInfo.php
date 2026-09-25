<?php
declare(strict_types=1);

/**
 * Netz, Land und Registry einer Adresse.
 *
 * Die Angaben stammen aus den Statistikdateien der fünf regionalen Registries (RIR).
 * Sie sagen, an wen ein Adressblock vergeben wurde – nicht, wo ein Gerät gerade steht.
 * Ein deutscher Block kann in einem Rechenzentrum irgendwo betrieben werden; für die
 * Frage „wessen Netz ist das" ist die Zuteilung aber die belastbarere Auskunft als
 * jede Standortschätzung.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-23 09:10
 */

namespace Honeypot;

final class NetworkInfo implements \JsonSerializable
{
	public function __construct(
		public readonly string $address,
		public readonly string $network,
		public readonly string $country,
		public readonly string $registry,
	) {
	}

	/**
	 * @return array<string, string>
	 */
	public function jsonSerialize(): array
	{
		return [
			'address' => $this->address,
			'network' => $this->network,
			'country' => $this->country,
			'registry' => $this->registry,
		];
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function fromArray(array $data): self
	{
		return new self(
			(string)($data['address'] ?? ''),
			(string)($data['network'] ?? ''),
			(string)($data['country'] ?? ''),
			(string)($data['registry'] ?? ''),
		);
	}
}
