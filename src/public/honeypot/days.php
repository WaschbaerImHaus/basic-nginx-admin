<?php
declare(strict_types=1);

/**
 * Kachel „Tage im Überblick": Anfragen und Sondierungen je Tag als Säulen, jede Säule
 * führt zu ihrem Tag.
 *
 * Bei einem Bereich zeigt sie dessen Tage (höchstens die letzten 62), bei einem
 * einzelnen Tag die zwei Wochen bis zu ihm – so ist er in seinem Umfeld zu sehen.
 *
 * Wird ausschliesslich von index.php eingebunden.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-25 23:05
 */

if (!function_exists('period_link')) {
	http_response_code(404);
	exit;
}

/** @var \Honeypot\ReportDatabase $db */
/** @var string $host */
/** @var \Honeypot\Period $period */

// Ein Tag mit den 13 Tagen davor, ein Bereich mit höchstens seinen letzten 62 Tagen.
$first = $period->isSingleDay() ? $period->before(13)->from : $period->from;
$windowDays = array_slice(\Honeypot\Period::between($first, $period->to)->days(), -62);
$series = $db->dailyTotals($host, \Honeypot\Period::between($windowDays[0], $period->to));
$peak = 1;
foreach ($series as $totals) {
	$peak = max($peak, $totals['requests']);
}
?>
<div class="tile">
	<h2>Tage im Überblick</h2>
	<div class="days" style="--count:<?= count($windowDays) ?>">
		<?php foreach ($windowDays as $date): $totals = $series[$date] ?? null; ?>
			<?php if ($totals === null): ?>
				<span class="d none" title="<?= h(\Honeypot\Period::day($date)->label()) ?>: keine Auswertung"></span>
			<?php else: ?>
				<a class="d<?= $period->isSingleDay() && $period->contains($date) ? ' selected' : '' ?>"
					href="<?= h(period_link(['tag' => $date])) ?>"
					title="<?= h(\Honeypot\Period::day($date)->label()) ?>: <?= $totals['requests'] ?> Anfragen, <?= $totals['probing'] ?> Sondierungen">
					<span class="all" style="height:<?= (int)round($totals['requests'] / $peak * 100) ?>%"></span>
					<span class="bad" style="height:<?= (int)round($totals['probing'] / $peak * 100) ?>%"></span>
				</a>
			<?php endif ?>
		<?php endforeach ?>
	</div>
	<div class="scale"><span><?= h(\Honeypot\Period::day($windowDays[0])->label()) ?></span><span><?= h(\Honeypot\Period::day($period->to)->label()) ?></span></div>
	<p class="hint">Säule = Anfragen des Tages, rot = davon Sondierungen (404). Ein Klick zeigt den Tag.
		<?php if ($period->length() > 62): ?>Gezeigt sind die letzten 62 Tage des Zeitraums.<?php endif ?></p>
</div>
