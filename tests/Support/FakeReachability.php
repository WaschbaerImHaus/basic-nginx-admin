<?php
declare(strict_types=1);

/**
 * Test-Ersatz für den Erreichbarkeitstest: liefert vorgegebene Ergebnisse.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-20 22:20
 */

namespace Tests\Support;

use VhostAdmin\Ssl\AcmeReachabilityInterface;
use VhostAdmin\Ssl\ReachabilityResult;
use VhostAdmin\Ssl\ReachabilityStatus;

final class FakeReachability implements AcmeReachabilityInterface
{
	/** @var list<array<string, string>> Aufrufe, zur Kontrolle im Test */
	public array $calls = [];
	public ReachabilityStatus $status = ReachabilityStatus::Ok;
	/** @var array<string, ReachabilityStatus> Ergebnis je Name, überschreibt $status */
	public array $statusByName = [];

	/**
	 * @param array<string, string> $targets
	 * @return array<string, ReachabilityResult>
	 */
	public function checkMany(array $targets): array
	{
		$this->calls[] = $targets;
		$results = [];
		foreach (array_keys($targets) as $domain) {
			$status = $this->statusByName[$domain] ?? $this->status;
			$results[$domain] = new ReachabilityResult($status, 'Testergebnis für ' . $domain, 200);
		}
		return $results;
	}
}
