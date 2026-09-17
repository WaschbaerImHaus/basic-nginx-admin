<?php
declare(strict_types=1);

/**
 * Test-Double: zählt Reloads, kann auf Wunsch fehlschlagen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-17 11:00
 */

namespace Tests\Support;

use VhostAdmin\Nginx\ReloaderInterface;

final class FakeReloader implements ReloaderInterface
{
	public int $calls = 0;
	public ?string $failWith = null;

	/**
	 * Zählt den Aufruf hoch und wirft eine Ausnahme, wenn $failWith gesetzt ist.
	 *
	 * @throws \RuntimeException wenn $failWith gesetzt ist
	 */
	public function reload(): void
	{
		$this->calls++;
		if ($this->failWith !== null) {
			throw new \RuntimeException($this->failWith);
		}
	}
}
