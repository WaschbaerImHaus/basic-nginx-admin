<?php
declare(strict_types=1);

/**
 * Test-Ersatz für die Namensauflösung: liefert vorgegebene Antworten, fragt nichts.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-23 10:00
 */

namespace Tests\Support;

use Honeypot\DnsResolverInterface;

final class FakeDns implements DnsResolverInterface
{
	/** @var array<string, string> Name => Adresse */
	public array $addresses = [];
	/** @var array<string, string> Adresse => Name */
	public array $reverse = [];
	/** @var list<string> Aufrufe, zur Kontrolle im Test */
	public array $calls = [];

	public function addressFor(string $host): ?string
	{
		$this->calls[] = "A $host";
		return $this->addresses[$host] ?? null;
	}

	public function reverseFor(string $address): ?string
	{
		$this->calls[] = "PTR $address";
		return $this->reverse[$address] ?? null;
	}
}
