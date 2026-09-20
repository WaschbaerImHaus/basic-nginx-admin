<?php
declare(strict_types=1);

/**
 * Test-Ersatz für die Benutzerverwaltung: merkt sich Namen, statt useradd aufzurufen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-20 22:35
 */

namespace Tests\Support;

use VhostAdmin\Php\SystemUsersInterface;

final class FakeSystemUsers implements SystemUsersInterface
{
	/** @var array<string, string> Benutzername => Heimatordner */
	public array $created = [];
	public bool $fail = false;

	public function exists(string $name): bool
	{
		return isset($this->created[$name]);
	}

	public function create(string $name, string $homeDir): void
	{
		if ($this->fail) {
			throw new \RuntimeException("Systembenutzer \"$name\" konnte nicht angelegt werden: absichtlicher Testfehler");
		}
		$this->created[$name] = $homeDir;
	}
}
