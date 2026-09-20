<?php
declare(strict_types=1);

/**
 * Test-Ersatz für den php-fpm-Reload: zählt Aufrufe, statt systemd anzusprechen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-20 22:20
 */

namespace Tests\Support;

use VhostAdmin\Php\FpmReloaderInterface;

final class FakeFpmReloader implements FpmReloaderInterface
{
	public int $reloads = 0;
	public bool $fail = false;

	public function reload(): void
	{
		if ($this->fail) {
			throw new \RuntimeException('php-fpm -t fehlgeschlagen: absichtlicher Testfehler');
		}
		$this->reloads++;
	}
}
