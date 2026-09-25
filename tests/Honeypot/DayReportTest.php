<?php
declare(strict_types=1);

/**
 * Tests der Tageskennzahlen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-22 17:00
 */

namespace Tests\Honeypot;

use Honeypot\DayReport;
use Honeypot\LogEntry;
use Honeypot\NetworkInfo;
use Honeypot\Peer;
use PHPUnit\Framework\TestCase;

final class DayReportTest extends TestCase
{
	/** @return list<LogEntry> */
	private function entries(array $specs): array
	{
		$out = [];
		foreach ($specs as [$time, $path, $status, $agent]) {
			$line = '10.200.0.1 - - [21/Sep/2026:' . $time . ' +0200] "GET ' . $path
				. ' HTTP/1.1" ' . $status . ' 5 "-" "' . $agent . '"';
			$out[] = LogEntry::fromLine($line);
		}
		return $out;
	}

	public function testCountsRequestsStatusesAndHours(): void
	{
		$report = DayReport::fromEntries('2026-09-21', $this->entries([
			['07:00:01', '/', '200', 'a'],
			['07:30:00', '/.env', '404', 'a'],
			['23:10:00', '/x', '404', 'b'],
		]), 0, [], true);

		self::assertSame(3, $report->requests);
		self::assertSame(2, $report->status['404']);
		self::assertSame(2, $report->hours['07']);
		self::assertSame(1, $report->hours['23']);
		self::assertSame(0, $report->hours['03'], 'jede Stunde ist vertreten, auch leere');
	}

	/**
	 * Nur die 404 sind Sondierung: Was es gibt, wurde gefunden, nicht gesucht.
	 */
	public function testOnlyMissingPathsCountAsProbingForLoot(): void
	{
		$report = DayReport::fromEntries('2026-09-21', $this->entries([
			['07:00:01', '/.env', '404', 'a'],
			['07:00:02', '/robots.txt', '200', 'a'],
		]), 0, [], true);
		self::assertSame(1, $report->loot['Zugangsdaten']);
		self::assertSame(1, $report->notFound['/.env']);
	}

	/**
	 * Der Abstand zwischen robots.txt und dem dort ausgeschlossenen Pfad trennt
	 * „liest und wertet aus" von „ruft blind alles ab".
	 */
	public function testMeasuresTheGapBetweenRobotsTxtAndTheForbiddenPath(): void
	{
		$report = DayReport::fromEntries('2026-09-21', $this->entries([
			['07:00:00', '/robots.txt', '200', 'a'],
			['07:00:04', '/admin/', '404', 'a'],
		]), 0, [], true);
		self::assertSame(1, $report->robots);
		self::assertSame(1, $report->admin);
		self::assertSame(1, $report->robotsThenAdmin);
		self::assertSame([4], $report->gaps);
	}

	public function testAdminWithoutRobotsTxtIsGuessworkNotAViolation(): void
	{
		$report = DayReport::fromEntries('2026-09-21', $this->entries([
			['07:00:00', '/admin/', '404', 'a'],
		]), 0, [], true);
		self::assertSame(1, $report->admin);
		self::assertSame(0, $report->robotsThenAdmin);
		self::assertSame([], $report->gaps);
	}

	/**
	 * Vier Kennungen mit exakt gleicher Anzahl sind ein Werkzeug, das durchwechselt –
	 * die Erkennung braucht keine Liste bekannter Bots.
	 */
	public function testGroupsAgentsThatAppearExactlyEquallyOften(): void
	{
		$specs = [];
		foreach (['Firefox/4.0', 'Chrome/6.0'] as $agent) {
			for ($i = 0; $i < 6; $i++) {
				$specs[] = [sprintf('08:%02d:00', $i), '/x', '404', $agent];
			}
		}
		$specs[] = ['09:00:00', '/y', '404', 'einzeln'];
		$report = DayReport::fromEntries('2026-09-21', $this->entries($specs), 0, [], true);
		self::assertArrayHasKey(6, $report->rotating);
		self::assertSame(['Firefox/4.0', 'Chrome/6.0'], $report->rotating[6]);
		self::assertArrayNotHasKey(1, $report->rotating, 'Einzelgänger sind kein Muster');
	}

	public function testKeepsTheProbesThatWereNotHttpAtAll(): void
	{
		$entries = [
			LogEntry::fromLine('10.200.0.1 - - [21/Sep/2026:10:00:00 +0200] "SSH-2.0-Go" 400 150 "-" "-"'),
			LogEntry::fromLine('10.200.0.1 - - [21/Sep/2026:10:00:01 +0200] "SSH-2.0-Go" 400 150 "-" "-"'),
			LogEntry::fromLine('10.200.0.1 - - [21/Sep/2026:10:00:02 +0200] "CONNECT x:443 HTTP/1.1" 400 150 "-" "-"'),
		];
		$report = DayReport::fromEntries('2026-09-21', $entries, 0, [], true);
		self::assertSame(3, $report->probeCount);
		self::assertSame(2, $report->probeKinds['SSH-Banner']);
		self::assertSame(1, $report->probeKinds['Proxy gesucht']);
	}

	/**
	 * Die Ansicht liest ausschliesslich die gespeicherte Fassung – was beim Speichern
	 * verlorenginge, wäre dort für immer weg.
	 */
	public function testSurvivesTheRoundTripThroughJson(): void
	{
		$original = DayReport::fromEntries('2026-09-21', $this->entries([
			['07:00:00', '/robots.txt', '200', 'a'],
			['07:00:04', '/admin/', '404', 'a'],
			['08:00:00', '/.env', '404', 'b'],
		]), 2, ['admin' => 3], true);

		$json = json_encode($original, JSON_THROW_ON_ERROR);
		$copy = DayReport::fromArray(json_decode((string)$json, true, 512, JSON_THROW_ON_ERROR));

		self::assertEquals($original, $copy);
		self::assertSame(3, $copy->logins['admin']);
		self::assertSame(2, $copy->unreadable);
		self::assertTrue($copy->complete);
	}

	/**
	 * Die Ereignisliste trägt die Detailansicht. Gewöhnliche Treffer gehören nicht
	 * hinein – sonst ersäuft das Auffällige im Alltäglichen.
	 */
	public function testKeepsOnlyNoteworthyRequestsAsEvents(): void
	{
		$report = DayReport::fromEntries('2026-09-21', $this->entries([
			['07:00:00', '/', '200', 'a'],
			['07:00:01', '/.env', '404', 'a'],
			['07:00:02', '/robots.txt', '200', 'a'],
		]), 0, [], true);

		$paths = array_column($report->events, 'path');
		self::assertContains('/.env', $paths, '404 ist Sondierung');
		self::assertContains('/robots.txt', $paths, 'robots.txt ist das Fallensignal');
		self::assertNotContains('/', $paths, 'ein gewöhnlicher Treffer ist kein Ereignis');
	}

	/**
	 * Drei Klassen nach Verhalten – keine Liste bekannter Bots, sonst fänden sich nur
	 * die, die ohnehin auf jeder Liste stehen.
	 */
	public function testSortsAgentsIntoThreeClassesByBehaviour(): void
	{
		$specs = [];
		foreach (['Firefox/4.0', 'Chrome/6.0'] as $agent) {
			for ($i = 0; $i < 6; $i++) {
				$specs[] = [sprintf('08:%02d:00', $i), '/x', '404', $agent];
			}
		}
		$specs[] = ['09:00:00', '/y', '404', 'libredtail-http'];
		$specs[] = ['09:00:01', '/y', '404', '-'];
		$specs[] = ['09:00:02', '/y', '404', '-'];
		$report = DayReport::fromEntries('2026-09-21', $this->entries($specs), 0, [], true);

		self::assertSame(
			['nennt sich' => 1, 'tarnt sich' => 12, 'nennt nichts' => 2],
			$report->agentClasses()
		);
		self::assertSame('tarnt sich', $report->agentClass('Firefox/4.0'));
		self::assertSame('nennt nichts', $report->agentClass('-'));
		self::assertSame('nennt sich', $report->agentClass('libredtail-http'));
	}

	/**
	 * Herkunft entsteht in einem eigenen Schritt (sie braucht Namensauflösung) und muss
	 * die Ablage überleben – die Ansicht liest ausschliesslich die gespeicherte Fassung.
	 */
	public function testCarriesPeersThroughJsonIncludingNetworkAndCountry(): void
	{
		$peer = new Peer(
			'about.censys.io', Peer::SELF_DECLARED, 5, '192.0.2.10',
			new NetworkInfo('192.0.2.10', '192.0.2.0/24', 'US', 'arin'), 'scanner.censys.io'
		);
		$report = DayReport::fromEntries('2026-09-21', [], 0, [], true)->withPeers([$peer]);

		$copy = DayReport::fromArray(json_decode(
			(string)json_encode($report, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR
		));

		self::assertEquals($report, $copy);
		self::assertSame('US', $copy->peers[0]->network?->country);
		self::assertSame('192.0.2.0/24', $copy->peers[0]->network?->network);
		self::assertSame('scanner.censys.io', $copy->peers[0]->reverse);
	}

	/**
	 * Gezählt werden Anfragen, nicht Gegenstellen: Zwei Scanner aus demselben Land mit
	 * sehr verschiedener Betriebsamkeit sind nicht dasselbe wie zwei gleich laute.
	 */
	public function testGroupsPeersByCountryAndNetworkWeightedByRequests(): void
	{
		$report = DayReport::fromEntries('2026-09-21', [], 0, [], true)->withPeers([
			new Peer('a.example', Peer::SELF_DECLARED, 10, '192.0.2.1', new NetworkInfo('192.0.2.1', '192.0.2.0/24', 'US', 'arin'), null),
			new Peer('b.example', Peer::SELF_DECLARED, 4, '192.0.2.2', new NetworkInfo('192.0.2.2', '192.0.2.0/24', 'US', 'arin'), null),
			new Peer('c.example', Peer::SELF_DECLARED, 7, '198.51.100.1', new NetworkInfo('198.51.100.1', '198.51.100.0/24', 'DE', 'ripencc'), null),
		]);

		self::assertSame(['US' => 14, 'DE' => 7], $report->countries());
		self::assertSame(['192.0.2.0/24' => 14, '198.51.100.0/24' => 7], $report->networks());
	}

	/**
	 * Ohne aufgelöste Herkunft bleiben die Listen leer, statt eine Zeile „unbekannt"
	 * zu erfinden, die wie eine Aussage aussieht.
	 */
	public function testPeersWithoutResolutionDoNotAppearInCountriesOrNetworks(): void
	{
		$report = DayReport::fromEntries('2026-09-21', [], 0, [], true)
			->withPeers([new Peer('gibtsnicht.example', Peer::SELF_DECLARED, 3)]);

		self::assertSame([], $report->countries());
		self::assertSame([], $report->networks());
		self::assertCount(1, $report->peers, 'die Gegenstelle selbst bleibt sichtbar');
	}
}
