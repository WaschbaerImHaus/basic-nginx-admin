<?php
declare(strict_types=1);

/**
 * Tests der Reload-Bestätigung: Ein Reload gilt erst, wenn nginx eine neue
 * Worker-Generation gestartet hat (BUGS.md, „Reload wird verschluckt").
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-27 14:10
 */

namespace Tests\Nginx;

use PHPUnit\Framework\TestCase;
use VhostAdmin\Nginx\ReloadConfirmation;
use VhostAdmin\Nginx\ReloadNotConfirmedException;

final class ReloadConfirmationTest extends TestCase
{
	/** @var list<int> aktuelle Worker */
	private array $workers = [10, 11];
	private int $triggers = 0;
	private float $slept = 0.0;
	/** @var list<list<int>> Worker, die der n-te Reload (ab 1) startet; fehlt einer, wirkt er nicht */
	private array $spawn = [];

	private function confirmation(): ReloadConfirmation
	{
		return new ReloadConfirmation(
			function (): void {
				$this->triggers++;
				if (isset($this->spawn[$this->triggers])) {
					$this->workers = array_merge($this->workers, $this->spawn[$this->triggers]);
				}
			},
			fn(): array => $this->workers,
			function (float $seconds): void {
				$this->slept += $seconds;
			},
		);
	}

	public function testAReloadThatStartsNewWorkersIsConfirmedAtOnce(): void
	{
		$this->spawn = [1 => [20, 21]];
		$this->confirmation()->run();
		self::assertSame(1, $this->triggers);
		self::assertLessThan(0.1, $this->slept);
	}

	/** Verschluckt: Der erste Reload bewirkt nichts, der zweite schon. */
	public function testASwallowedReloadIsRepeated(): void
	{
		$this->spawn = [2 => [30]];
		$this->confirmation()->run();
		self::assertSame(2, $this->triggers);
	}

	/**
	 * Bleibt es dabei, meldet der Aufruf das – sonst meldete die Oberfläche Erfolg,
	 * während nginx eine ältere, womöglich großzügigere Konfiguration ausliefert.
	 */
	public function testGivesUpAfterThreeAttempts(): void
	{
		try {
			$this->confirmation()->run();
			self::fail('hätte aufgeben müssen');
		} catch (ReloadNotConfirmedException $e) {
			self::assertStringContainsString('nicht übernommen', $e->getMessage());
		}
		self::assertSame(3, $this->triggers);
		self::assertEqualsWithDelta(4.5, $this->slept, 0.1, 'je Versuch höchstens 1,5 s');
	}

	/** nginx lief gar nicht: Der Reload startet es, jeder Worker ist neu. */
	public function testStartingNginxCounts(): void
	{
		$this->workers = [];
		$this->spawn = [1 => [40]];
		$this->confirmation()->run();
		self::assertSame(1, $this->triggers);
	}

	/** Alte Worker, die sich beenden, sind keine neue Generation. */
	public function testFinishingOldWorkersAreNoNewGeneration(): void
	{
		$this->spawn = [2 => [50]];
		$confirmation = new ReloadConfirmation(
			function (): void {
				$this->triggers++;
				// Die alten Worker beenden sich, neue kommen erst beim zweiten Versuch.
				$this->workers = [];
				if (isset($this->spawn[$this->triggers])) {
					$this->workers = $this->spawn[$this->triggers];
				}
			},
			fn(): array => $this->workers,
			function (float $seconds): void {
			},
		);
		$confirmation->run();
		self::assertSame(2, $this->triggers);
	}
}
