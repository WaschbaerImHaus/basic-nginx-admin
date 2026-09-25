<?php
declare(strict_types=1);

/**
 * Kachel „Kalender" der Honigtopf-Ansicht: ein Monatsblatt zur Wahl eines Tages oder
 * einer ganzen Kalenderwoche, eingefärbt nach Sondierungen.
 *
 * Wird ausschliesslich von index.php eingebunden; dort sind $db, $host, $period und
 * $month bereits geprüft.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-25 23:05
 */

if (!function_exists('period_link')) {
	// Direkt aufgerufen statt eingebunden: nichts ausgeben.
	http_response_code(404);
	exit;
}

/** @var \Honeypot\ReportDatabase $db */
/** @var string $host */
/** @var \Honeypot\Period $period */
/** @var string $month */

$calendar = new \Honeypot\MonthCalendar($month, $db->dailyTotals($host, \Honeypot\MonthCalendar::range($month)), $period);
$monthRange = \Honeypot\MonthCalendar::range($month);
?>
<div class="tile">
	<h2>Kalender
		<a href="<?= h(period_link(['von' => $monthRange->from, 'bis' => $monthRange->to])) ?>">ganzer Monat &rarr;</a></h2>
	<div class="month">
		<a href="<?= h(link_to(['monat' => $calendar->previousMonth()])) ?>" aria-label="Monat davor">&lsaquo;</a>
		<strong><?= h($calendar->label()) ?></strong>
		<a href="<?= h(link_to(['monat' => $calendar->nextMonth()])) ?>" aria-label="Monat danach">&rsaquo;</a>
	</div>
	<table class="calendar">
		<tr><th title="Kalenderwoche">KW</th><th>Mo</th><th>Di</th><th>Mi</th><th>Do</th><th>Fr</th><th>Sa</th><th>So</th></tr>
		<?php foreach ($calendar->weeks() as $week): $weekPeriod = $week['period']; ?>
			<tr>
				<td class="week"><a href="<?= h(period_link(['von' => $weekPeriod->from, 'bis' => $weekPeriod->to])) ?>"
					title="Woche <?= (int)$week['week'] ?>: <?= h($weekPeriod->label()) ?>"><?= (int)$week['week'] ?></a></td>
				<?php foreach ($week['days'] as $cell): ?>
					<?php if ($cell === null): ?>
						<td></td>
					<?php else: $classes = 'day l' . $cell['level'] . ($cell['selected'] ? ' selected' : ''); ?>
						<td class="<?= $classes ?>">
							<?php if ($cell['data']): ?>
								<a href="<?= h(period_link(['tag' => $cell['date']])) ?>"
									title="<?= h(\Honeypot\Period::day($cell['date'])->label()) ?>: <?= $cell['requests'] ?> Anfragen, <?= $cell['probing'] ?> Sondierungen"><?= $cell['day'] ?></a>
							<?php else: ?>
								<span title="keine Auswertung"><?= $cell['day'] ?></span>
							<?php endif ?>
						</td>
					<?php endif ?>
				<?php endforeach ?>
			</tr>
		<?php endforeach ?>
	</table>
	<p class="hint">Ein Tag wählt diesen Tag, die Kalenderwoche die ganze Woche. Je kräftiger die Farbe,
		desto mehr Sondierungen – gemessen am stärksten Tag des Monats. Einen freien Bereich wählen
		„von“ und „bis“ oben.</p>
</div>
