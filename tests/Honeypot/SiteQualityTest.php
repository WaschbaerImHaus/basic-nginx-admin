<?php
declare(strict_types=1);

/**
 * Tests der Qualitätsanpassung der Blumenwiese (FPS-Zähler und Stufenwahl).
 *
 * Die Logik steht als JavaScript in honeypot/site/index.html zwischen den Markierungen
 * „// <adaptive-quality>" und „// </adaptive-quality>". Dieser Test schneidet den
 * Abschnitt heraus, bettet ihn mit Prüfungen in eine Seite und lässt sie von einem
 * kopflosen Chrome ausführen – eine Node-Laufzeit gibt es auf dem Rechner nicht.
 * Ohne Chrome wird der Test übersprungen (Einrichtung siehe debugging/meadow-check.sh).
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-25 23:20
 */

namespace Tests\Honeypot;

use PHPUnit\Framework\TestCase;

final class SiteQualityTest extends TestCase
{
	/** @var array<string, mixed>|null Ergebnisse aller Prüfungen, einmal je Lauf ermittelt */
	private static ?array $results = null;

	public function testTheSectionIsMarkedInThePage(): void
	{
		self::assertNotSame('', self::section(), 'Abschnitt <adaptive-quality> fehlt in index.html');
	}

	/** Der Zähler mittelt über ein Fenster und liefert erst an dessen Ende einen Wert. */
	public function testTheFrameCounterAveragesOverAWindow(): void
	{
		$r = self::results();
		self::assertNull($r['counterBeforeWindow']);
		self::assertEqualsWithDelta(60.0, $r['counterAt60'], 0.5);
		self::assertEqualsWithDelta(25.0, $r['counterAt25'], 0.5);
	}

	/**
	 * Pausen zählen nicht: Nach einem Tabwechsel liefert der Browser ein Bild mit
	 * Sekunden Abstand – das ist keine schlechte Bildrate, sondern keine.
	 */
	public function testTheFrameCounterIgnoresPauses(): void
	{
		$r = self::results();
		self::assertEqualsWithDelta(60.0, $r['counterWithPause'], 0.5);
	}

	/** Die ersten Messungen nach dem Start zählen nicht – dort ruckelt jede Seite. */
	public function testIgnoresTheWarmUp(): void
	{
		self::assertSame(0, self::results()['levelAfterWarmUp']);
	}

	/** Zwei schwache Messungen hintereinander senken die Stufe, eine einzelne nicht. */
	public function testStepsDownAfterSustainedLowFrameRate(): void
	{
		$r = self::results();
		self::assertSame(0, $r['levelAfterOneDip']);
		self::assertSame(1, $r['levelAfterTwoLow']);
	}

	/** Ganz unten ist Schluss – keine Stufe unterhalb der sparsamsten. */
	public function testStopsAtTheLowestLevel(): void
	{
		$r = self::results();
		self::assertSame($r['levelCount'] - 1, $r['levelAfterLongLow']);
	}

	/** Zwischen den Schwellen bleibt alles, wie es ist. */
	public function testHoldsBetweenTheThresholds(): void
	{
		self::assertSame(2, self::results()['levelInBetween']);
	}

	/** Erholt sich die Bildrate dauerhaft, kommt die Qualität stufenweise zurück. */
	public function testStepsUpAfterSustainedHighFrameRate(): void
	{
		$r = self::results();
		self::assertSame(2, $r['levelAfterShortHigh']);
		self::assertSame(1, $r['levelAfterLongHigh']);
	}

	/**
	 * Kein Pendeln: Bricht die Bildrate gleich nach dem Hochstufen wieder ein, war die
	 * höhere Stufe zu teuer. Sie wird dann nicht noch einmal versucht.
	 */
	public function testDoesNotOscillate(): void
	{
		$r = self::results();
		self::assertSame(2, $r['levelAfterFailedUpgrade']);
		self::assertSame(2, $r['levelAfterMuchLaterHigh']);
	}

	/**
	 * Die Stufen werden nur billiger: Randunschärfe, Gauß je Blume und Blumendichte
	 * fallen nacheinander weg, nie kommt auf einer tieferen Stufe etwas zurück.
	 */
	public function testLevelsOnlyGetCheaper(): void
	{
		$levels = self::results()['levels'];
		self::assertGreaterThanOrEqual(4, count($levels));
		self::assertSame(['nearBlur' => true, 'blur' => true, 'density' => 1], array_intersect_key($levels[0], array_flip(['nearBlur', 'blur', 'density'])));
		$last = end($levels);
		self::assertFalse($last['blur']);
		self::assertFalse($last['nearBlur']);
		self::assertLessThan(1, $last['density']);
		for ($i = 1; $i < count($levels); $i++) {
			self::assertLessThanOrEqual((int)$levels[$i - 1]['nearBlur'], (int)$levels[$i]['nearBlur']);
			self::assertLessThanOrEqual((int)$levels[$i - 1]['blur'], (int)$levels[$i]['blur']);
			self::assertLessThanOrEqual($levels[$i - 1]['density'], $levels[$i]['density']);
			self::assertNotSame($levels[$i - 1], $levels[$i], 'jede Stufe muss etwas einsparen');
		}
	}

	/** Die Auswahl weniger Blumen ist gleichmässig: etwa der Anteil der Dichte, ohne Häufung. */
	public function testThinningKeepsAnEvenShare(): void
	{
		$r = self::results();
		self::assertEqualsWithDelta(0.6 * 620, $r['keptAt60'], 5);
		self::assertEqualsWithDelta(0.35 * 620, $r['keptAt35'], 5);
	}

	/** Den Abschnitt aus der Seite schneiden. */
	private static function section(): string
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/honeypot/site/index.html');
		return preg_match('~// <adaptive-quality>(.*?)// </adaptive-quality>~s', $html, $m) === 1 ? $m[1] : '';
	}

	/**
	 * Führt die Prüfungen einmal im Browser aus.
	 *
	 * @return array<string, mixed>
	 */
	private static function results(): array
	{
		if (self::$results !== null) {
			return self::$results;
		}
		$chrome = getenv('CHROME') ?: (getenv('HOME') . '/tools/chrome-headless-shell-linux64/chrome-headless-shell');
		if (!is_executable($chrome)) {
			self::markTestSkipped('chrome-headless-shell fehlt: ' . $chrome);
		}
		$page = '<!DOCTYPE html><meta charset="utf-8"><pre id="out">läuft</pre><script>' . self::section() . <<<'JS'

const out = {};
// Zähler: 30 Bilder zu 1/60 s ergeben noch kein volles Fenster (1 s).
let rate = new FrameRate(1);
let value = null;
for (let i = 0; i < 30; i++) { value = rate.tick(1 / 60); }
out.counterBeforeWindow = value;
for (let i = 0; i < 40 && value === null; i++) { value = rate.tick(1 / 60); }
out.counterAt60 = value;
value = null;
for (let i = 0; i < 40 && value === null; i++) { value = rate.tick(1 / 25); }
out.counterAt25 = value;
rate = new FrameRate(1);
value = null;
for (let i = 0; i < 70 && value === null; i++) { value = rate.tick(i === 20 ? 4.5 : 1 / 60); }
out.counterWithPause = value;

// Stufenwahl. Die ersten Messungen sind Aufwärmzeit.
const feed = (governor, fps, times) => { for (let i = 0; i < times; i++) { governor.measure(fps); } return governor.level; };
let g = new QualityGovernor(QUALITY_LEVELS);
out.levelAfterWarmUp = feed(g, 10, g.warmup);
out.levelAfterOneDip = (g.measure(20), g.measure(60), g.level);
out.levelAfterTwoLow = feed(g, 20, 2);
out.levelAfterLongLow = feed(g, 5, 40);
out.levelCount = QUALITY_LEVELS.length;

g = new QualityGovernor(QUALITY_LEVELS);
feed(g, 60, g.warmup);
feed(g, 20, 4);
out.levelInBetween = feed(g, 50, 60);
out.levelAfterShortHigh = feed(g, 60, 3);
out.levelAfterLongHigh = feed(g, 60, 5);
// Gleich nach dem Hochstufen bricht es ein: zurück, und diese Stufe nicht wieder.
out.levelAfterFailedUpgrade = feed(g, 20, 2);
out.levelAfterMuchLaterHigh = feed(g, 60, 100);

out.levels = QUALITY_LEVELS;
let kept60 = 0, kept35 = 0;
for (let i = 0; i < 620; i++) {
	if (plantRank(i) < 0.6) { kept60++; }
	if (plantRank(i) < 0.35) { kept35++; }
}
out.keptAt60 = kept60;
out.keptAt35 = kept35;
document.getElementById('out').textContent = JSON.stringify(out);
</script>
JS;
		$file = sys_get_temp_dir() . '/site-quality-' . getmypid() . '.html';
		file_put_contents($file, $page);
		$dom = (string)shell_exec(escapeshellarg($chrome) . ' --no-sandbox --headless --virtual-time-budget=500 --dump-dom '
			. escapeshellarg('file://' . $file) . ' 2>/dev/null');
		unlink($file);
		if (preg_match('~<pre id="out">(.*?)</pre>~s', $dom, $m) !== 1) {
			self::fail('keine Ausgabe aus dem Browser');
		}
		$decoded = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true);
		if (!is_array($decoded)) {
			self::fail('Skriptfehler im Browser, Ausgabe: ' . substr($m[1], 0, 200));
		}
		return self::$results = $decoded;
	}
}
