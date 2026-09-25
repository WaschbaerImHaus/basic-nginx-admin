<?php
declare(strict_types=1);

/**
 * Reichert Gegenstellen um Adresse, Netz, Land und Rückwärtsnamen an.
 *
 * Was hier nicht ermittelt werden kann, bleibt leer. Eine erfundene Herkunft wäre
 * schlimmer als eine fehlende: Die Ansicht soll zeigen, was belegt ist.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-23 10:00
 */

namespace Honeypot;

final class PeerResolver
{
	/** @var array<string, ?string> Name => Adresse; gilt über alle Tage eines Laufs */
	private array $addressCache = [];
	/** @var array<string, ?string> Adresse => Rückwärtsname */
	private array $reverseCache = [];
	/** @var array<string, ?NetworkInfo> Adresse => Netzangaben */
	private array $networkCache = [];

	public function __construct(
		private readonly DnsResolverInterface $dns,
		private readonly NetworkRegistry $networks,
	) {
	}

	/**
	 * @param list<Peer> $peers
	 * @return list<Peer> dieselben Gegenstellen mit nachgetragenen Angaben
	 */
	public function resolve(array $peers): array
	{
		// 1. Adressen ermitteln, jeden Namen nur einmal fragen: DNS ist der langsamste
		//    Teil der Auswertung. Der Zwischenspeicher gilt über alle Tage eines Laufs –
		//    dieselben Scanner tauchen Tag für Tag auf.
		$addressFor = [];
		foreach ($peers as $peer) {
			if (array_key_exists($peer->host, $addressFor)) {
				continue;
			}
			if (!array_key_exists($peer->host, $this->addressCache)) {
				$this->addressCache[$peer->host] = $peer->isAddress()
					? $peer->host
					: $this->dns->addressFor($peer->host);
			}
			$addressFor[$peer->host] = $this->addressCache[$peer->host];
		}

		// 2. Netz und Land für alle noch unbekannten Adressen in EINEM Durchlauf durch
		//    die Tabelle – sie hat rund eine Drittelmillion Zeilen.
		$addresses = array_values(array_unique(array_filter($addressFor)));
		$unknown = array_values(array_filter(
			$addresses,
			fn(string $address): bool => !array_key_exists($address, $this->networkCache)
		));
		if ($unknown !== []) {
			$found = $this->networks->lookupMany($unknown);
			foreach ($unknown as $address) {
				$this->networkCache[$address] = $found[$address] ?? null;
			}
		}

		// 3. Rückwärtsnamen: sagt oft den Betreiber, auch wenn die Registry nur das
		//    Land kennt.
		foreach ($addresses as $address) {
			if (!array_key_exists($address, $this->reverseCache)) {
				$this->reverseCache[$address] = $this->dns->reverseFor($address);
			}
		}

		$out = [];
		foreach ($peers as $peer) {
			$address = $addressFor[$peer->host];
			$out[] = $peer->withResolution(
				$address,
				$address !== null ? ($this->networkCache[$address] ?? null) : null,
				$address !== null ? ($this->reverseCache[$address] ?? null) : null,
			);
		}
		return $out;
	}
}
