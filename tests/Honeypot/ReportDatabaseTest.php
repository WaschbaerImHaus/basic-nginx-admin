<?php
declare(strict_types=1);

/**
 * Tests der Ablage fertiger Auswertungen in SQLite.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-25 23:05
 */

namespace Tests\Honeypot;

use Honeypot\DayReport;
use Honeypot\LogEntry;
use Honeypot\NetworkInfo;
use Honeypot\Peer;
use Honeypot\Period;
use Honeypot\ReportDatabase;
use Honeypot\ReportStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempDir;

final class ReportDatabaseTest extends TestCase
{
	private string $dir;
	private ReportDatabase $db;

	protected function setUp(): void
	{
		$this->dir = TempDir::create();
		$this->db = ReportDatabase::open($this->dir . '/honeypot.sqlite');
	}

	protected function tearDown(): void
	{
		TempDir::remove($this->dir);
	}

	/**
	 * Ein Tag aus Logzeilen: [Uhrzeit, Anfragezeile, Status, Kennung].
	 *
	 * @param list<array{string, string, string, string}> $specs
	 */
	private function day(string $date, array $specs, array $logins = [], bool $complete = true): DayReport
	{
		[$y, $m, $d] = explode('-', $date);
		$month = date('M', (int)mktime(0, 0, 0, (int)$m, 1));
		$entries = [];
		foreach ($specs as [$time, $request, $status, $agent]) {
			$entries[] = LogEntry::fromLine(sprintf(
				'10.200.0.1 - - [%s/%s/%s:%s +0200] "%s" %s 5 "-" "%s"',
				$d, $month, $y, $time, $request, $status, $agent
			));
		}
		return DayReport::fromEntries($date, $entries, 0, $logins, $complete);
	}

	/** Ein Tag mit allem, was ein Bericht tragen kann. */
	private function richDay(string $date): DayReport
	{
		$specs = [
			['07:00:00', 'GET /robots.txt HTTP/1.1', '200', 'a'],
			['07:00:04', 'GET /admin/ HTTP/1.1', '404', 'a'],
			['07:10:00', 'GET /.env HTTP/1.1', '404', 'Bot (+https://scan.example/)'],
			['08:00:00', 'SSH-2.0-Go', '400', '-'],
			['09:00:00', 'CONNECT x.example:443 HTTP/1.1', '400', '-'],
		];
		foreach (['Firefox/4.0', 'Chrome/6.0'] as $agent) {
			for ($i = 0; $i < 6; $i++) {
				$specs[] = [sprintf('10:%02d:00', $i), 'GET /wp-config.php HTTP/1.1', '404', $agent];
			}
		}
		return $this->day($date, $specs, ['admin' => 3, 'root' => 1])->withPeers([
			new Peer('scan.example', Peer::SELF_DECLARED, 1, '192.0.2.10',
				new NetworkInfo('192.0.2.10', '192.0.2.0/24', 'US', 'arin'), 'host.scan.example'),
			new Peer('x.example', Peer::PROXY_TARGET, 1),
		]);
	}

	/**
	 * Der Kern: Was gespeichert wird, kommt unverändert zurück. Sonst zeigte die
	 * Ansicht nach der Umstellung andere Zahlen als vorher.
	 */
	public function testASavedDayComesBackUnchanged(): void
	{
		$original = $this->richDay('2026-09-21');
		$this->db->save('mfsvr.de', $original, 2);
		self::assertEquals($original, $this->db->load('mfsvr.de', Period::day('2026-09-21')));
	}

	public function testNothingStoredGivesNothing(): void
	{
		self::assertNull($this->db->load('mfsvr.de', Period::day('2026-09-21')));
		self::assertNull($this->db->info('mfsvr.de', '2026-09-21'));
	}

	/**
	 * Ein Zeitraum ist die Summe seiner Tage: Zahlen addiert, Listen zusammengeführt.
	 */
	public function testARangeAddsUpItsDays(): void
	{
		$this->db->save('mfsvr.de', $this->richDay('2026-09-21'), 2);
		$this->db->save('mfsvr.de', $this->day('2026-09-22', [
			['07:30:00', 'GET /.env HTTP/1.1', '404', 'b'],
			['23:00:00', 'GET / HTTP/1.1', '200', 'b'],
		], [], false), 2);

		$range = $this->db->load('mfsvr.de', Period::between('2026-09-21', '2026-09-22'));

		self::assertNotNull($range);
		self::assertSame(19, $range->requests);
		self::assertSame(2, $range->notFound['/.env']);
		self::assertSame(12, $range->notFound['/wp-config.php']);
		self::assertSame(15, $range->status['404']);
		self::assertSame(4, $range->hours['07'], '3 am ersten, 1 am zweiten Tag');
		self::assertSame(1, $range->hours['23']);
		self::assertSame(0, $range->hours['03'], 'jede Stunde ist vertreten');
		self::assertSame([4], $range->gaps);
		self::assertFalse($range->complete, 'ein offener Tag macht den Zeitraum offen');
		self::assertSame(['admin' => 3, 'root' => 1], $range->logins);
	}

	/**
	 * Gegenstellen, die an mehreren Tagen auftauchen, zählen zusammen; Adresse und
	 * Netz stammen vom jüngsten Tag.
	 */
	public function testPeersAreSummedAcrossDays(): void
	{
		$peer = static fn(int $n, string $address): Peer => new Peer('scan.example', Peer::SELF_DECLARED, $n, $address,
			new NetworkInfo($address, '192.0.2.0/24', 'US', 'arin'), null);
		$this->db->save('h.example', $this->day('2026-09-21', [])->withPeers([$peer(2, '192.0.2.1')]), 2);
		$this->db->save('h.example', $this->day('2026-09-22', [])->withPeers([$peer(5, '192.0.2.9')]), 2);

		$range = $this->db->load('h.example', Period::between('2026-09-21', '2026-09-22'));
		self::assertCount(1, $range->peers);
		self::assertSame(7, $range->peers[0]->requests);
		self::assertSame('192.0.2.9', $range->peers[0]->address, 'die jüngste Auflösung gilt');
	}

	/**
	 * Gleich häufige Kennungen werden auf den Summen des Zeitraums erkannt.
	 */
	public function testRotationIsDetectedOnTheRangeTotals(): void
	{
		$this->db->save('mfsvr.de', $this->richDay('2026-09-21'), 2);
		$this->db->save('mfsvr.de', $this->richDay('2026-09-22'), 2);
		$range = $this->db->load('mfsvr.de', Period::between('2026-09-21', '2026-09-22'));
		self::assertArrayHasKey(12, $range->rotating);
		self::assertCount(2, $range->rotating[12]);
	}

	/**
	 * Eine neue Fassung desselben Tages ersetzt die alte vollständig – ohne Reste in
	 * irgendeiner Tabelle.
	 */
	public function testReplacingADayLeavesNoRemnants(): void
	{
		$this->db->save('mfsvr.de', $this->richDay('2026-09-21'), 1);
		$this->db->save('mfsvr.de', $this->day('2026-09-21', [['07:00:00', 'GET /neu HTTP/1.1', '404', 'x']]), 2);

		$day = $this->db->load('mfsvr.de', Period::day('2026-09-21'));
		self::assertSame(['/neu' => 1], $day->notFound);
		self::assertSame([], $day->peers);
		self::assertSame([], $day->logins);
		self::assertSame([], $day->gaps);
		self::assertCount(1, $day->events);
	}

	/**
	 * Was die Auswertung braucht, um einen Tag zu überspringen: ist er abgeschlossen,
	 * mit welcher Fassung ausgewertet, wie viele Anfragen hatte er.
	 */
	public function testReportsWhatIsStoredAboutADay(): void
	{
		$this->db->save('mfsvr.de', $this->richDay('2026-09-21'), 2);
		self::assertSame(
			['complete' => true, 'version' => 2, 'requests' => 17],
			$this->db->info('mfsvr.de', '2026-09-21')
		);
	}

	public function testListsHostsAndTheirFirstAndLastDay(): void
	{
		$this->db->save('zebra.example', $this->day('2026-09-20', []), 2);
		$this->db->save('mfsvr.de', $this->day('2026-09-19', []), 2);
		$this->db->save('mfsvr.de', $this->day('2026-09-25', []), 2);
		self::assertSame(['mfsvr.de', 'zebra.example'], $this->db->hosts());
		self::assertSame(['2026-09-19', '2026-09-25'], $this->db->bounds('mfsvr.de'));
		self::assertNull($this->db->bounds('gibtsnicht.example'));
	}

	/**
	 * Für den Kalender und den Tagesverlauf: je Tag Anfragen und Sondierungen, auch für
	 * Tage ohne Daten nichts erfunden.
	 */
	public function testGivesTotalsPerDay(): void
	{
		$this->db->save('mfsvr.de', $this->richDay('2026-09-21'), 2);
		$this->db->save('mfsvr.de', $this->day('2026-09-23', [['07:00:00', 'GET / HTTP/1.1', '200', 'x']]), 2);
		self::assertSame(
			[
				'2026-09-21' => ['requests' => 17, 'probing' => 14, 'probes' => 2],
				'2026-09-23' => ['requests' => 1, 'probing' => 0, 'probes' => 0],
			],
			$this->db->dailyTotals('mfsvr.de', Period::between('2026-09-20', '2026-09-30'))
		);
	}

	public function testGivesSearchedPathsPerDay(): void
	{
		$this->db->save('mfsvr.de', $this->day('2026-09-21', [['07:00:00', 'GET /a HTTP/1.1', '404', 'x']]), 2);
		$this->db->save('mfsvr.de', $this->day('2026-09-22', [['07:00:00', 'GET /b HTTP/1.1', '404', 'x']]), 2);
		self::assertSame(
			['2026-09-21' => ['/a' => 1], '2026-09-22' => ['/b' => 1]],
			$this->db->dailyPaths('mfsvr.de', Period::between('2026-09-21', '2026-09-22'))
		);
	}

	/**
	 * Die Ereignisliste eines Zeitraums kann gross werden; gefiltert und begrenzt wird
	 * deshalb in der Datenbank, nicht in PHP.
	 */
	public function testFiltersEventsInTheDatabase(): void
	{
		$this->db->save('mfsvr.de', $this->richDay('2026-09-21'), 2);
		$this->db->save('mfsvr.de', $this->richDay('2026-09-22'), 2);
		$range = Period::between('2026-09-21', '2026-09-22');

		$all = $this->db->events('mfsvr.de', $range, '', '', 500);
		self::assertSame(34, $all['total']);

		$probing = $this->db->events('mfsvr.de', $range, 'sondierung', '', 500);
		self::assertSame(28, $probing['total']);

		$services = $this->db->events('mfsvr.de', $range, 'dienst', '', 500);
		self::assertSame(4, $services['total']);

		$trap = $this->db->events('mfsvr.de', $range, 'falle', '', 500);
		self::assertSame(4, $trap['total'], 'robots.txt und /admin/ an zwei Tagen');

		$search = $this->db->events('mfsvr.de', $range, '', '.env', 500);
		self::assertSame(2, $search['total']);

		$limited = $this->db->events('mfsvr.de', $range, '', '', 5);
		self::assertCount(5, $limited['rows']);
		self::assertSame(34, $limited['total'], 'die Gesamtzahl gilt auch bei begrenzter Liste');
		self::assertSame('2026-09-22', $limited['rows'][0]['date'], 'neueste zuerst');
	}

	/**
	 * Die Suche geht als Parameter an die Datenbank. Ein % oder _ in der Eingabe ist
	 * ein Zeichen, kein Platzhalter; SQL im Suchfeld bleibt Text.
	 */
	public function testSearchIsLiteralAndSafe(): void
	{
		$this->db->save('mfsvr.de', $this->richDay('2026-09-21'), 2);
		$range = Period::day('2026-09-21');
		self::assertSame(0, $this->db->events('mfsvr.de', $range, '', '%', 500)['total']);
		self::assertSame(0, $this->db->events('mfsvr.de', $range, '', "' OR 1=1 --", 500)['total']);
		self::assertSame(12, $this->db->events('mfsvr.de', $range, '', 'wp-config', 500)['total']);
	}

	/**
	 * Die Ansicht öffnet die Datenbank nur lesend: Selbst ein Fehler in ihr kann keine
	 * Auswertung verändern.
	 */
	public function testAReadOnlyConnectionCannotWrite(): void
	{
		$this->db->save('mfsvr.de', $this->richDay('2026-09-21'), 2);
		$reader = ReportDatabase::openReadOnly($this->dir . '/honeypot.sqlite');
		self::assertNotNull($reader);
		self::assertSame(17, $reader->load('mfsvr.de', Period::day('2026-09-21'))?->requests);
		$this->expectException(\PDOException::class);
		$reader->save('mfsvr.de', $this->richDay('2026-09-22'), 2);
	}

	public function testReadOnlyWithoutAFileGivesNothing(): void
	{
		self::assertNull(ReportDatabase::openReadOnly($this->dir . '/fehlt.sqlite'));
	}

	/**
	 * Die bisherigen Tagesberichte (JSON) werden übernommen – nur, wo die Datenbank
	 * den Tag noch nicht kennt. Sie tragen Fassung 1; liegen die Logs des Tages noch
	 * vor, wertet der nächste Lauf ihn neu aus.
	 */
	public function testImportsTheOldJsonReportsWhereNothingIsStored(): void
	{
		$store = new ReportStore($this->dir . '/json');
		$store->save('mfsvr.de', $this->richDay('2026-09-19'));
		$store->save('mfsvr.de', $this->richDay('2026-09-20'));
		$this->db->save('mfsvr.de', $this->day('2026-09-20', []), 2);

		self::assertSame(1, $this->db->importJson($store));
		self::assertSame(1, $this->db->info('mfsvr.de', '2026-09-19')['version']);
		self::assertSame(0, $this->db->info('mfsvr.de', '2026-09-20')['requests'], 'Vorhandenes bleibt');
		self::assertSame(0, $this->db->importJson($store), 'ein zweiter Lauf übernimmt nichts doppelt');
	}

	/**
	 * Geschrieben wird als root, gelesen vom Benutzer des php-fpm-Pools über die Gruppe
	 * des Ordners.
	 */
	public function testTheFileIsReadableForTheGroupOfItsDirectory(): void
	{
		$this->db->save('mfsvr.de', $this->richDay('2026-09-21'), 2);
		$file = $this->dir . '/honeypot.sqlite';
		self::assertSame('0640', sprintf('%04o', (fileperms($file) ?: 0) & 07777));
		self::assertSame(filegroup($this->dir), filegroup($file));
	}
}
