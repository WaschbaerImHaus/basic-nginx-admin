<?php
declare(strict_types=1);

/**
 * Tests der Vorschlagsregeln: Ein Vorschlag erscheint, wenn die Zahlen ihn tragen –
 * nicht, weil er grundsätzlich denkbar wäre.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-22 17:00
 */

namespace Tests\Honeypot;

use Honeypot\DayReport;
use Honeypot\LogEntry;
use Honeypot\Suggestions;
use PHPUnit\Framework\TestCase;

final class SuggestionsTest extends TestCase
{
	/** @return list<LogEntry> */
	private function probing(int $count, string $path = '/.env'): array
	{
		$out = [];
		for ($i = 0; $i < $count; $i++) {
			$out[] = LogEntry::fromLine(
				'10.200.0.1 - - [21/Sep/2026:' . sprintf('07:%02d:00', $i % 60)
				. ' +0200] "GET ' . $path . ' HTTP/1.1" 404 5 "-" "a"'
			);
		}
		return $out;
	}

	public function testAQuietDayGetsNoSuggestions(): void
	{
		$report = DayReport::fromEntries('2026-09-21', $this->probing(1, '/impressum'), 0, [], true);
		$out = (new Suggestions())->forReport($report);
		self::assertCount(1, $out);
		self::assertStringContainsString('Keine', $out[0]);
	}

	public function testSuggestsBaitFilesOnlyWhenCredentialsAreActuallySoughtOften(): void
	{
		$few = (new Suggestions())->forReport(DayReport::fromEntries('2026-09-21', $this->probing(2), 0, [], true));
		$many = (new Suggestions())->forReport(DayReport::fromEntries('2026-09-21', $this->probing(8), 0, [], true));
		self::assertStringNotContainsString('Köderdatei', implode("\n", $few));
		self::assertStringContainsString('Köderdatei', implode("\n", $many));
	}

	public function testMentionsTheNumbersItIsBasedOn(): void
	{
		$out = implode("\n", (new Suggestions())->forReport(
			DayReport::fromEntries('2026-09-21', $this->probing(25), 0, ['admin' => 2], true)
		));
		self::assertStringContainsString('25', $out, 'die Zahl der Sondierungen gehört in den Vorschlag');
	}

	/**
	 * Umgesetzt am 2026-09-25: eigene Kachel für Nicht-HTTP, Gruppierung nach gleicher
	 * Häufigkeit, Vergleich der Pfade über Tage. Ein Vorschlag, der vorschlägt, was die
	 * Seite schon zeigt, ist Rauschen – und lässt die echten Vorschläge untergehen.
	 */
	public function testDoesNotSuggestWhatTheViewAlreadyShows(): void
	{
		$entries = $this->probing(30, '/impressum');
		foreach (['Firefox/4.0', 'Chrome/6.0'] as $agent) {
			for ($i = 0; $i < 6; $i++) {
				$entries[] = LogEntry::fromLine('10.200.0.1 - - [21/Sep/2026:08:0' . $i . ':00 +0200] "GET / HTTP/1.1" 404 5 "-" "' . $agent . '"');
			}
		}
		for ($i = 0; $i < 4; $i++) {
			$entries[] = LogEntry::fromLine('10.200.0.1 - - [21/Sep/2026:09:00:0' . $i . ' +0200] "SSH-2.0-Go" 400 150 "-" "-"');
		}
		$out = implode("\n", (new Suggestions())->forReport(DayReport::fromEntries('2026-09-21', $entries, 0, [], true)));

		self::assertStringNotContainsString('Eigene Kachel', $out);
		self::assertStringNotContainsString('nach Häufigkeit gruppieren', $out);
		self::assertStringNotContainsString('über Tage vergleichen', $out);
	}
}
