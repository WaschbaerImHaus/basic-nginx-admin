<?php
declare(strict_types=1);

/**
 * Tests der Ablage der Tagesberichte.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-22 17:00
 */

namespace Tests\Honeypot;

use Honeypot\DayReport;
use Honeypot\ReportStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempDir;

final class ReportStoreTest extends TestCase
{
	private string $dir;
	private ReportStore $store;

	protected function setUp(): void
	{
		$this->dir = TempDir::create();
		$this->store = new ReportStore($this->dir);
	}

	protected function tearDown(): void
	{
		TempDir::remove($this->dir);
	}

	private function report(string $date): DayReport
	{
		return DayReport::fromEntries($date, [], 0, [], true);
	}

	public function testSavesAndLoadsAReport(): void
	{
		$this->store->save('mfsvr.de', $this->report('2026-09-21'));
		$loaded = $this->store->load('mfsvr.de', '2026-09-21');
		self::assertSame('2026-09-21', $loaded?->date);
	}

	public function testListsDaysNewestFirst(): void
	{
		foreach (['2026-09-19', '2026-09-21', '2026-09-20'] as $date) {
			$this->store->save('mfsvr.de', $this->report($date));
		}
		self::assertSame(['2026-09-21', '2026-09-20', '2026-09-19'], $this->store->days('mfsvr.de'));
	}

	public function testListsHostsAlphabetically(): void
	{
		$this->store->save('zebra.example', $this->report('2026-09-21'));
		$this->store->save('mfsvr.de', $this->report('2026-09-21'));
		self::assertSame(['mfsvr.de', 'zebra.example'], $this->store->hosts());
	}

	public function testUnknownHostOrDayGivesNothingInsteadOfAnError(): void
	{
		self::assertNull($this->store->load('gibtsnicht.example', '2026-09-21'));
		self::assertSame([], $this->store->days('gibtsnicht.example'));
	}

	/**
	 * Host und Datum kommen in der Ansicht aus der Adresszeile. Ohne strenge Prüfung
	 * liesse sich damit jede Datei ausserhalb des Berichtsordners lesen – die Ansicht
	 * läuft zwar mit open_basedir, aber darauf darf sich diese Klasse nicht verlassen.
	 */
	public function testRefusesNamesThatCouldLeaveTheReportDirectory(): void
	{
		foreach (['../etc/passwd', 'a/b', '.', '..', '', 'mf svr.de', "mfsvr.de\0.json"] as $evil) {
			self::assertNull($this->store->load($evil, '2026-09-21'), 'Host: ' . $evil);
			self::assertSame([], $this->store->days($evil), 'Host: ' . $evil);
		}
		foreach (['../../2026-09-21', '2026-09-21/../x', 'gestern'] as $evil) {
			self::assertNull($this->store->load('mfsvr.de', $evil), 'Datum: ' . $evil);
		}
	}

	public function testSavingRefusesAnUnsafeHostName(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->store->save('../evil', $this->report('2026-09-21'));
	}

	/**
	 * Der Bericht des laufenden Tages wird bei jedem Lauf neu geschrieben; abends ist
	 * er vollständiger als morgens. Die spätere Fassung muss gewinnen.
	 */
	public function testANewerRunReplacesTheReportOfTheSameDay(): void
	{
		$this->store->save('mfsvr.de', $this->report('2026-09-21'));
		$entries = [\Honeypot\LogEntry::fromLine('10.200.0.1 - - [21/Sep/2026:07:00:00 +0200] "GET /x HTTP/1.1" 404 5 "-" "a"')];
		$this->store->save('mfsvr.de', DayReport::fromEntries('2026-09-21', $entries, 0, [], true));
		self::assertSame(1, $this->store->load('mfsvr.de', '2026-09-21')?->requests);
	}

	/**
	 * Geschrieben wird als root (nur root liest die Logs), gelesen vom Benutzer des
	 * php-fpm-Pools über die Gruppe des Ordners. Ohne feste Rechte entstünde die Datei
	 * mit der Maske des Dienstes – je nach Einstellung für alle lesbar oder für die
	 * Gruppe gar nicht.
	 */
	public function testWritesTheReportWithFixedPermissionsAndTheDirectoryGroup(): void
	{
		$this->store->save('mfsvr.de', $this->report('2026-09-21'));
		$file = $this->dir . '/mfsvr.de/2026-09-21.json';
		self::assertSame('0640', sprintf('%04o', (fileperms($file) ?: 0) & 07777));
		self::assertSame(filegroup($this->dir . '/mfsvr.de'), filegroup($file));
	}
}
