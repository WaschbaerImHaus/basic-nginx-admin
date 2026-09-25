<?php
declare(strict_types=1);

/**
 * Namensauflösung über den Auflöser des Systems.
 *
 * Läuft ausschliesslich in der täglichen Auswertung, nie in der Ansicht: Die Ansicht
 * fragt nichts im Netz nach.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-25 10:30
 */

namespace Honeypot;

final class SystemDns implements DnsResolverInterface
{
	public function addressFor(string $host): ?string
	{
		$records = @dns_get_record($host, DNS_A);
		if (is_array($records) && isset($records[0]['ip'])) {
			return (string)$records[0]['ip'];
		}
		$records = @dns_get_record($host, DNS_AAAA);
		if (is_array($records) && isset($records[0]['ipv6'])) {
			return self::unwrapNat64((string)$records[0]['ipv6']);
		}
		return null;
	}

	/**
	 * Holt die IPv4-Adresse aus einer DNS64-Adresse (64:ff9b::/96, RFC 6052).
	 *
	 * Dieser Rechner hat nach aussen nur IPv6; sein Auflöser erfindet für reine
	 * IPv4-Namen solche Adressen. Sie gehören keiner Registry – nachgeschlagen würde
	 * nichts. Alle anderen Adressen bleiben unverändert.
	 */
	public static function unwrapNat64(string $address): string
	{
		$packed = @inet_pton($address);
		if ($packed === false || strlen($packed) !== 16) {
			return $address;
		}
		$prefix = "\x00\x64\xff\x9b" . str_repeat("\0", 8);
		if (substr($packed, 0, 12) !== $prefix) {
			return $address;
		}
		return (string)inet_ntop(substr($packed, 12, 4));
	}

	public function reverseFor(string $address): ?string
	{
		$name = @gethostbyaddr($address);
		// gethostbyaddr() gibt die Adresse selbst zurück, wenn es keinen PTR gibt.
		return $name === false || $name === $address ? null : $name;
	}
}
