<?php
declare(strict_types=1);

/**
 * Tests der Entscheidung, welche Tage ausgewertet werden.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-25 23:05
 */

namespace Tests\Honeypot;

use Honeypot\Analyzer;
use Honeypot\DayReport;
use Honeypot\NetworkRegistry;
use Honeypot\PeerResolver;
use Honeypot\Period;
use Honeypot\ReportDatabase;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeDns;
use Tests\Support\TempDir;

final class AnalyzerTest extends TestCase
{
	private string $dir;
	private ReportDatabase $db;
	private FakeDns $dns;
	private Analyzer $analyzer;

	protected function setUp(): void
	{
		$this->dir = TempDir::create();
		$this->db = ReportDatabase::open($this->dir . '/honeypot.sqlite');
		$this->dns = new FakeDns();
		$this->analyzer = new Analyzer(
			$this->db,
			new PeerResolver($this->dns, new NetworkRegistry($this->dir . '/keine-tabelle.txt'))
		);
	}

	protected function tearDown(): void
	{
		TempDir::remove($this->dir);
	}

	/** Logzeilen: je Datum (TT) eine Anzahl Anfragen mit selbst genannter Kennung. */
	private function lines(array $perDay): array
	{
		$out = [];
		foreach ($perDay as $day => $count) {
			for ($i = 0; $i < $count; $i++) {
				$out[] = sprintf(
					'10.200.0.1 - - [%02d/Sep/2026:10:%02d:00 +0200] "GET /x%d HTTP/1.1" 404 5 "-" "Bot (+https://scan%d.example/)"',
					$day, $i % 60, $i, $day
				);
			}
		}
		return $out;
	}

	private function analyse(array $perDay, string $today, bool $cut = false): array
	{
		return $this->analyzer->run('mfsvr.de', $this->lines($perDay), [], ['mfsvr.de'], $today, $cut);
	}

	/**
	 * Nutzerwunsch vom 2026-09-25: Vergangene Tage werden nicht erneut ausgewertet –
	 * auch nicht per DNS nachgeschlagen.
	 */
	public function testAFinishedDayIsNotAnalysedAgain(): void
	{
		$first = $this->analyse([23 => 3, 24 => 2, 25 => 1], '2026-09-25');
		self::assertSame(['2026-09-23', '2026-09-24', '2026-09-25'], $first['analysed']);

		// Jeder Tageslauf ist ein neuer Prozess mit leerem DNS-Zwischenspeicher.
		$this->dns = new FakeDns();
		$this->analyzer = new Analyzer(
			$this->db,
			new PeerResolver($this->dns, new NetworkRegistry($this->dir . '/keine-tabelle.txt'))
		);
		$second = $this->analyse([23 => 3, 24 => 2, 25 => 4], '2026-09-25');
		self::assertSame(['2026-09-25'], $second['analysed'], 'nur der laufende Tag');
		self::assertSame(['2026-09-23', '2026-09-24'], $second['skipped']);
		self::assertSame(['A scan25.example'], $this->dns->calls, 'nur die Gegenstelle des laufenden Tages');
		self::assertSame(4, $this->db->info('mfsvr.de', '2026-09-25')['requests']);
	}

	/**
	 * Der laufende Tag ist nie fertig; am nächsten Morgen wird er ein letztes Mal
	 * vollständig ausgewertet und gilt dann als abgeschlossen.
	 */
	public function testYesterdayIsCompletedOnTheNextRun(): void
	{
		$this->analyse([25 => 2], '2026-09-25');
		self::assertFalse($this->db->info('mfsvr.de', '2026-09-25')['complete']);

		$next = $this->analyse([25 => 5, 26 => 1], '2026-09-26');
		self::assertContains('2026-09-25', $next['analysed']);
		self::assertTrue($this->db->info('mfsvr.de', '2026-09-25')['complete']);
		self::assertSame(5, $this->db->info('mfsvr.de', '2026-09-25')['requests']);
	}

	/**
	 * Tage aus einer älteren Auswertung (etwa die übernommenen JSON-Berichte) werden neu
	 * ausgewertet, solange ihre Logs noch da sind.
	 */
	public function testAnOlderAnalysisIsRedoneWhileTheLogsExist(): void
	{
		$this->db->save('mfsvr.de', DayReport::fromEntries('2026-09-23', [], 0, [], true), 1);
		$result = $this->analyse([23 => 3], '2026-09-25');
		self::assertContains('2026-09-23', $result['analysed']);
		self::assertSame(Analyzer::VERSION, $this->db->info('mfsvr.de', '2026-09-23')['version']);
	}

	/**
	 * Der wichtigste Schutz: Ein gespeicherter Tag wird nie durch eine kleinere Fassung
	 * ersetzt. Am Rand der Aufbewahrungsfrist enthält die älteste Logdatei nur noch einen
	 * Teil eines Tages (logrotate schneidet um 06:20); eine Neuauswertung würde den
	 * vollständig gespeicherten Tag sonst stillschweigend verkleinern.
	 */
	public function testNeverReplacesADayWithASmallerOne(): void
	{
		$lines = $this->lines([23 => 10]);
		$this->db->save('mfsvr.de', DayReport::fromEntries(
			'2026-09-23',
			array_map([\Honeypot\LogEntry::class, 'fromLine'], $lines),
			0, [], true
		), 1);

		$result = $this->analyse([23 => 4], '2026-09-25');
		self::assertSame(['2026-09-23'], $result['kept']);
		self::assertSame(10, $this->db->info('mfsvr.de', '2026-09-23')['requests']);
	}

	/**
	 * Liegt die älteste Logdatei an der Aufbewahrungsgrenze, fehlt vom ältesten Tag
	 * vermutlich der Anfang. Er wird gespeichert, aber als unvollständig markiert.
	 */
	public function testTheOldestDayAtTheRetentionLimitIsMarkedIncomplete(): void
	{
		$this->analyse([20 => 2, 21 => 3], '2026-09-25', true);
		self::assertFalse($this->db->info('mfsvr.de', '2026-09-20')['complete']);
		self::assertTrue($this->db->info('mfsvr.de', '2026-09-21')['complete']);
	}

	public function testLoginAttemptsGoToTheirDay(): void
	{
		$errors = [
			'2026/09/24 10:00:00 [error] 1#1: *1 user "admin" was not found in "/x"',
			'2026/09/25 10:00:00 [error] 1#1: *2 user "root" was not found in "/x"',
		];
		$this->analyzer->run('mfsvr.de', $this->lines([24 => 1, 25 => 1]), $errors, ['mfsvr.de'], '2026-09-25', false);
		self::assertSame(['admin' => 1], $this->db->load('mfsvr.de', Period::day('2026-09-24'))?->logins);
		self::assertSame(['root' => 1], $this->db->load('mfsvr.de', Period::day('2026-09-25'))?->logins);
	}

	/**
	 * Gegenstellen werden beim Auswerten aufgelöst und mit dem Tag gespeichert.
	 */
	public function testStoresThePeersOfADay(): void
	{
		$this->dns->addresses['scan24.example'] = '192.0.2.4';
		$this->analyse([24 => 2], '2026-09-25');
		$day = $this->db->load('mfsvr.de', Period::day('2026-09-24'));
		self::assertSame('scan24.example', $day?->peers[0]->host);
		self::assertSame('192.0.2.4', $day?->peers[0]->address);
	}

	/**
	 * Köderkennungen: Der Lauf übernimmt ausgegebene Kennungen und sucht ihre Benutzung
	 * in allen gelesenen Zeilen – auch an Tagen, die er sonst überspringt.
	 */
	public function testTracksDecoyTokensAcrossDays(): void
	{
		$issued = json_encode(['time' => '2026-09-24T10:00:00+02:00', 'token' => '0123456789',
			'request' => 'GET /.env HTTP/1.1', 'status' => '200', 'agent' => 'S']);
		$later = '10.200.0.1 - - [25/Sep/2026:08:00:00 +0200] "GET /backup/db-0123456789.sql.gz HTTP/1.1" 404 5 "-" "C"';
		$lines = array_merge($this->lines([24 => 1]), [$later]);
		$this->analyzer->run('mfsvr.de', $lines, [], ['mfsvr.de'], '2026-09-26', false, [$issued]);
		// Zweiter Lauf, alle Tage abgeschlossen: nichts doppelt.
		$this->analyzer->run('mfsvr.de', $lines, [], ['mfsvr.de'], '2026-09-26', false, [$issued]);

		$activity = $this->db->decoyActivity('mfsvr.de', Period::between('2026-09-24', '2026-09-25'));
		self::assertSame(1, $activity['issued']);
		self::assertCount(1, $activity['uses']);
		self::assertSame('Anfrage', $activity['uses'][0]['source']);
	}

	/** Änderungen an der Auswertung heben die Fassung – sonst blieben alte Tage ohne Köderzählung. */
	public function testTheVersionCoversDecoys(): void
	{
		self::assertGreaterThanOrEqual(3, Analyzer::VERSION);
	}

	/**
	 * Seit Selbsttests (127.0.0.1) nicht mehr zählen, hat ein Tag mit Selbsttests weniger
	 * Anfragen als gespeichert. Das ist keine Rotation: Der Tag wird neu gerechnet, statt
	 * mit den alten Selbsttests „behalten" zu werden.
	 */
	public function testLocalRequestsDoNotCountAsMissingLines(): void
	{
		$lines = $this->lines([24 => 3]);
		$lines[] = '127.0.0.1 - - [24/Sep/2026:11:00:00 +0200] "GET /.env HTTP/2.0" 200 5 "-" "selbsttest"';
		// Früherer Stand: 4 Anfragen samt Selbsttest, ältere Fassung.
		$this->db->save('mfsvr.de', DayReport::fromArray(['date' => '2026-09-24', 'complete' => true, 'requests' => 4]), 2);
		$result = $this->analyzer->run('mfsvr.de', $lines, [], ['mfsvr.de'], '2026-09-26', false);
		self::assertSame(['2026-09-24'], $result['analysed']);
		self::assertSame(3, $this->db->load('mfsvr.de', Period::day('2026-09-24'))->requests);
	}
}
