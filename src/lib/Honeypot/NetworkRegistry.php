<?php
declare(strict_types=1);

/**
 * Schlägt Netz, Land und Registry einer Adresse in der aufbereiteten RIR-Tabelle nach.
 *
 * Die Tabelle baut `honeypot/update-networks.sh` aus den Statistikdateien der fünf
 * regionalen Registries. Sie liegt lokal; nachgeschlagen wird **ohne Netzzugriff** –
 * weder die Auswertung noch die Ansicht fragt zur Laufzeit irgendwo an.
 *
 * Gesucht wird in einem Durchlauf durch die Datei statt über eine Suchstruktur: Die
 * Tabelle hat rund eine Viertelmillion Zeilen, je Auswertung stehen aber nur eine
 * Handvoll Adressen an. Ein Durchlauf ist dafür schneller als jeder Index und braucht
 * keinen Speicher für die ganze Tabelle.
 *
 * Adressen werden als 32 Hexzeichen verglichen (IPv4 als IPv4-mapped IPv6). Dadurch
 * liegen beide Familien in einer Tabelle, und der Vergleich ist ein Zeichenkettenvergleich
 * ohne Ganzzahlarithmetik – 128 Bit passen in keine PHP-Ganzzahl.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-23 09:10
 */

namespace Honeypot;

final class NetworkRegistry
{
	public function __construct(private readonly string $tableFile)
	{
	}

	/**
	 * Gibt es überhaupt eine Tabelle? Ohne sie bleibt die Herkunft leer, statt zu raten.
	 */
	public function isAvailable(): bool
	{
		return is_file($this->tableFile) && is_readable($this->tableFile);
	}

	/**
	 * Netz, Land und Registry einer einzelnen Adresse.
	 */
	public function lookup(string $address): ?NetworkInfo
	{
		return $this->lookupMany([$address])[$address] ?? null;
	}

	/**
	 * Dasselbe für mehrere Adressen in einem Durchlauf durch die Tabelle.
	 *
	 * @param list<string> $addresses
	 * @return array<string, NetworkInfo> gefundene Adresse => Angaben
	 */
	public function lookupMany(array $addresses): array
	{
		$wanted = [];
		foreach ($addresses as $address) {
			$key = self::toHex($address);
			if ($key !== null) {
				$wanted[$address] = $key;
			}
		}
		if ($wanted === [] || !$this->isAvailable()) {
			return [];
		}

		$found = [];
		$handle = fopen($this->tableFile, 'r');
		if ($handle === false) {
			return [];
		}
		while (($line = fgets($handle)) !== false) {
			// start end land registry netz
			$parts = explode(' ', trim($line));
			if (count($parts) !== 5) {
				continue;
			}
			[$start, $end, $country, $registry, $network] = $parts;
			foreach ($wanted as $address => $key) {
				if (isset($found[$address]) || $key < $start || $key > $end) {
					continue;
				}
				$found[$address] = new NetworkInfo($address, $network, $country, $registry);
			}
			if (count($found) === count($wanted)) {
				break;
			}
		}
		fclose($handle);
		return $found;
	}

	/**
	 * Adresse als 32 Hexzeichen, IPv4 als IPv4-mapped IPv6. null, wenn es keine
	 * Adresse ist.
	 */
	private static function toHex(string $address): ?string
	{
		$packed = @inet_pton($address);
		if ($packed === false) {
			return null;
		}
		if (strlen($packed) === 4) {
			$packed = str_repeat("\0", 10) . "\xff\xff" . $packed;
		}
		return bin2hex($packed);
	}
}
