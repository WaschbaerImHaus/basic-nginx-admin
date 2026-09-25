<?php
declare(strict_types=1);

/**
 * Detailansichten der Honigtopf-Ansicht: die vollständigen Listen hinter den Kacheln.
 *
 * Wird ausschliesslich von index.php eingebunden; dort sind $report, $host, $period
 * und $view bereits geprüft. Eigene Datei, damit die Übersicht lesbar bleibt. Alles
 * bezieht sich auf den gewählten Zeitraum – einen Tag oder einen Bereich.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-25 23:05
 */

if (!function_exists('period_link')) {
	// Direkt aufgerufen statt eingebunden: nichts ausgeben.
	http_response_code(404);
	exit;
}

/** @var \Honeypot\DayReport $report */
/** @var \Honeypot\ReportDatabase $db */
/** @var \Honeypot\Period $period */
/** @var \Honeypot\PeriodSelection $selection */
/** @var string $view */
/** @var string $host */
/** @var string $span */
/** @var ?\Honeypot\PathTrend $trend */

$classifier = new \Honeypot\LootClassifier();
?>
<p class="crumb"><a href="<?= h(link_to(['ansicht' => ''])) ?>">&larr; Übersicht</a>
	· <?= h($host) ?> · <?= h($period->label()) ?></p>

<div class="tile">
<?php if ($view === 'pfade'): $group = param('gruppe'); ?>

	<h2>Gesuchte Pfade, die es nie gab</h2>
	<div class="filters">
		<a class="tag <?= $group === '' ? 'ok' : '' ?>" href="<?= h(link_to(['gruppe' => ''])) ?>">alle</a>
		<?php foreach (array_keys(\Honeypot\LootClassifier::GROUPS) as $name): ?>
			<a class="tag <?= $group === $name ? 'bad' : '' ?>" href="<?= h(link_to(['gruppe' => $name])) ?>"><?= h($name) ?></a>
		<?php endforeach ?>
		<a class="tag <?= $group === '-' ? 'warn' : '' ?>" href="<?= h(link_to(['gruppe' => '-'])) ?>">ohne Gruppe</a>
	</div>
	<table>
		<tr><th class="num">Treffer</th><th>Pfad</th><th>Beutegruppe</th></tr>
		<?php $shown = 0; foreach ($report->notFound as $path => $count):
			$found = $classifier->classify((string)$path) ?? '';
			if ($group !== '' && ($group === '-' ? $found !== '' : $found !== $group)) { continue; }
			$shown++; ?>
			<tr>
				<td class="num"><?= (int)$count ?></td>
				<td class="mono"><?= h((string)$path) ?></td>
				<td><?= $found === '' ? '<span class="tag">–</span>' : '<span class="tag bad">' . h($found) . '</span>' ?></td>
			</tr>
		<?php endforeach ?>
	</table>
	<?php if ($shown === 0): ?><p class="empty">Nichts in dieser Gruppe.</p><?php endif ?>
	<p class="hint">Jeder Pfad hier war ein Griff ins Leere: Die Seite ist statisch, es gab nie eine
		Anwendung, eine Datenbank oder eine Konfigurationsdatei zu holen.</p>

<?php elseif ($view === 'kennungen'): ?>

	<h2>Kennungen</h2>
	<table>
		<tr><th class="num">Anfragen</th><th>Klasse</th><th>User-Agent</th></tr>
		<?php foreach ($report->agents as $agent => $count): $class = $report->agentClass((string)$agent); ?>
			<tr>
				<td class="num"><?= (int)$count ?></td>
				<td><span class="tag <?= $class === 'tarnt sich' ? 'bad' : ($class === 'nennt nichts' ? 'warn' : '') ?>"><?= h($class) ?></span></td>
				<td class="mono"><?= (string)$agent === '-' ? '<em>keine</em>' : h((string)$agent) ?></td>
			</tr>
		<?php endforeach ?>
	</table>
	<?php if ($report->rotating !== []): ?>
		<p class="hint"><strong>Gleiche Häufigkeit, verschiedene Kennungen.</strong> Diese Gruppen kamen
			<em>genau</em> gleich oft vor – das ist ein Werkzeug, das seine Kennung durchwechselt, kein Zufall:</p>
		<table>
			<tr><th class="num">je</th><th class="num">Kennungen</th><th>Beispiel</th></tr>
			<?php foreach ($report->rotating as $count => $list): ?>
				<tr>
					<td class="num"><?= (int)$count ?>x</td>
					<td class="num"><?= count($list) ?></td>
					<td class="mono"><?= h((string)$list[0]) ?></td>
				</tr>
			<?php endforeach ?>
		</table>
	<?php endif ?>

<?php elseif ($view === 'dienste'): ?>

	<h2>Anfragen, die gar kein Webzugriff waren</h2>
	<table>
		<tr><th class="num">Anzahl</th><th>Art</th><th>Rohdaten der Anfragezeile</th></tr>
		<?php foreach ($report->probes as $raw => $count): $kind = \Honeypot\LogEntry::probeKindOf((string)$raw); ?>
			<tr class="probe">
				<td class="num"><?= (int)$count ?></td>
				<td><span class="tag warn"><?= h($kind ?? 'unbekannt') ?></span></td>
				<td class="mono"><?= h((string)$raw) ?></td>
			</tr>
		<?php endforeach ?>
	</table>
	<?php if ($report->probes === []): ?><p class="empty"><?= ucfirst(h($span)) ?> keine.</p><?php endif ?>
	<p class="hint">Diese Zeilen stehen mit Status 400 im Log. Sie suchen keinen Webinhalt, sondern einen
		Dienst: ein SSH-Banner auf Port 443, einen offenen Proxy, ein Binärprotokoll. Eine
		Portscanner-Kennung wie <span class="mono">MGLNDD_…</span> trägt sogar die Adresse des Scanners
		im Namen – die einzige Stelle, an der hier überhaupt eine Gegenstelle sichtbar wird.</p>

<?php elseif ($view === 'anmeldungen'): ?>

	<h2>Versuchte Anmeldungen</h2>
	<table>
		<tr><th class="num">Versuche</th><th>Benutzername</th></tr>
		<?php foreach ($report->logins as $user => $count): ?>
			<tr><td class="num"><?= (int)$count ?></td>
			<td class="mono"><?= (string)$user === '' ? '<em>(leer)</em>' : h((string)$user) ?></td></tr>
		<?php endforeach ?>
	</table>
	<?php if ($report->logins === []): ?><p class="empty"><?= ucfirst(h($span)) ?> keine.</p><?php endif ?>
	<p class="hint">Aus dem <span class="mono">error.log</span>, solange der Host einen Verzeichnisschutz
		trägt. Passwörter stehen dort nicht und werden hier auch nicht gesammelt – wer Zugangsdaten
		einsammelt, betreibt keinen Honigtopf mehr.</p>

<?php elseif ($view === 'verlauf'):
	// Spalten: bei einem Tag er selbst und die 14 davor, bei einem Bereich dessen Tage
	// (höchstens die letzten 31 – breiter wird die Tabelle unlesbar). Tage ohne einen
	// einzigen 404 bekommen trotzdem ihre Spalte.
	$first = $period->isSingleDay() ? $period->before(14)->from : $period->from;
	$columns = \Honeypot\Period::between(array_slice(\Honeypot\Period::between($first, $period->to)->days(), -31)[0], $period->to);
	$daily = array_map(static fn(): array => [], $db->dailyTotals($host, $columns));
	foreach ($db->dailyPaths($host, $columns) as $date => $paths) {
		$daily[$date] = $paths;
	}
	$grid = \Honeypot\PathTrend::fromDailyPaths($daily);
?>

	<h2>Sondierungspfade über Tage</h2>
	<?php if ($trend === null || count($grid->dates()) < 2): ?>
		<p class="empty">Noch zu wenige Tage zum Vergleichen – die Matrix entsteht ab dem zweiten Berichtstag.</p>
	<?php else: $fresh = $trend !== null && $trend->hasHistory() ? $trend->newToday() : []; $dates = $grid->dates(); ?>
		<p class="hint" style="margin-top:0">
			<?php if ($trend->hasHistory()): ?>
				<?= count($fresh) ?> Pfade wurden <?= $period->isSingleDay() ? 'am' : 'im Zeitraum' ?>
				<?= h($period->label()) ?> zum ersten Mal seit <?= count($trend->dates()) - 1 ?> Tagen gesucht – sie stehen rot.
			<?php else: ?>
				Vor <?= h($period->label()) ?> liegen keine ausgewerteten Tage; was neu ist, lässt sich erst mit
				Tagen davor sagen.
			<?php endif ?>
			Leere Felder heissen: an dem Tag nicht gesucht.</p>
		<div class="scroll">
		<table class="matrix">
			<tr>
				<th>Pfad</th>
				<?php foreach ($dates as $date): ?>
					<th class="num"><?= h(substr($date, 8, 2) . '.' . substr($date, 5, 2) . '.') ?></th>
				<?php endforeach ?>
				<th>zuerst</th>
			</tr>
			<?php foreach ($grid->matrix() as $path => $row): $isNew = isset($fresh[$path]); ?>
				<tr class="<?= $isNew ? 'violation' : '' ?>">
					<td class="mono"><?= h((string)$path) ?></td>
					<?php foreach ($row as $count): ?>
						<td class="num cell<?= $count > 0 ? ' hit' : '' ?>"><?= $count > 0 ? (int)$count : '' ?></td>
					<?php endforeach ?>
					<td class="mono"><?= h((string)$grid->firstSeen((string)$path)) ?></td>
				</tr>
			<?php endforeach ?>
		</table>
		</div>
		<p class="hint">Häufigste Pfade über den ganzen Zeitraum oben. Ein roter Pfad mit Treffern nur ganz links
			ist frisch; einer, der jeden Tag gleichmässig kommt, gehört zum Grundrauschen der Scanner.</p>
	<?php endif ?>

<?php elseif ($view === 'herkunft'): ?>

	<h2>Gegenstellen</h2>
	<?php if ($report->peers === []): ?>
		<p class="empty"><?= ucfirst(h($span)) ?> hat sich keine Gegenstelle zu erkennen gegeben.</p>
	<?php else: ?>
	<table>
		<tr><th class="num">Anfragen</th><th>Quelle</th><th>Name</th><th>Adresse</th><th>Netz</th><th>Land</th><th>Rückwärtsname</th></tr>
		<?php foreach ($report->peers as $peer): ?>
			<tr>
				<td class="num"><?= (int)$peer->requests ?></td>
				<td><span class="tag <?= $peer->kind === \Honeypot\Peer::PAYLOAD ? 'bad' : ($peer->kind === \Honeypot\Peer::CONNECTION ? 'ok' : '') ?>"><?= h($peer->kind) ?></span></td>
				<td class="mono"><?= h($peer->host) ?></td>
				<td class="mono"><?= h($peer->address ?? '–') ?></td>
				<td class="mono"><?= h($peer->network?->network ?? '–') ?></td>
				<td><?= $peer->network !== null ? h(country_name($peer->network->country)) . ' <span class="tag">' . h($peer->network->country) . '</span>' : '–' ?></td>
				<td class="mono"><?= h($peer->reverse ?? '–') ?></td>
			</tr>
		<?php endforeach ?>
	</table>
	<?php endif ?>
	<p class="hint"><strong>Was die Quellen bedeuten.</strong>
		<em>Selbstauskunft</em>: die URL, mit der ein Werkzeug sich in seiner Kennung ausweist – nachprüfbar, aber
		frei behauptet. <em>Proxy-Ziel</em>: wohin jemand über diesen Rechner weiter wollte.
		<em>Nachgeladen</em>: die Stelle, von der ein Exploit-Versuch etwas holen wollte – meist die Ablage
		des Angreifers und der härteste Fund hier. <em>Verbindung</em>: die tatsächliche Gegenstelle; erscheint
		erst, wenn der Tunnel die echte Adresse durchreicht.</p>
	<p class="hint">Netz und Land stammen aus den Statistikdateien der fünf Registries (RIPE, ARIN, APNIC,
		LACNIC, AFRINIC) und sagen, <em>an wen</em> ein Block vergeben ist – nicht, wo das Gerät steht.</p>

<?php else: /* ereignisse */
	$what = param('was');
	$needle = trim(param('q'));
	$filters = [
		'' => 'alle',
		'sondierung' => 'Sondierungen (404)',
		'dienst' => 'kein Webzugriff',
		'falle' => 'robots.txt und /admin',
	];
	if (!array_key_exists($what, $filters)) {
		$what = '';
	}
	// Gefiltert wird in der Datenbank: Über einen längeren Zeitraum sind es schnell
	// Zehntausende Zeilen, gezeigt werden die neuesten 500.
	$limit = 500;
	$found = $db->events($host, $period, $what, $needle, $limit);
	$rows = $found['rows'];
?>

	<h2>Ereignisse</h2>
	<div class="filters">
		<?php foreach ($filters as $key => $label): ?>
			<a class="tag <?= $what === $key ? 'ok' : '' ?>" href="<?= h(link_to(['was' => $key, 'q' => $needle])) ?>"><?= h($label) ?></a>
		<?php endforeach ?>
		<form method="get" style="display:flex;gap:.4rem">
			<input type="hidden" name="host" value="<?= h($host) ?>">
			<?php foreach ($selection->query() as $name => $value): ?>
				<input type="hidden" name="<?= h($name) ?>" value="<?= h($value) ?>">
			<?php endforeach ?>
			<input type="hidden" name="ansicht" value="ereignisse">
			<input type="hidden" name="was" value="<?= h($what) ?>">
			<input type="search" name="q" value="<?= h($needle) ?>" placeholder="Pfad oder Kennung">
			<button>Suchen</button>
		</form>
	</div>
	<p class="hint" style="margin-top:0"><?= $found['total'] ?> passende auffällige
		Anfragen<?= $found['total'] > $limit ? ', gezeigt werden die neuesten ' . $limit : '' ?>, neueste zuerst.
		Gewöhnliche Treffer stehen bewusst nicht in der Liste.</p>
	<table>
		<tr><?php if (!$period->isSingleDay()): ?><th>Tag</th><?php endif ?><th>Zeit</th><th>Verb</th><th>Pfad bzw. Anfragezeile</th><th class="num">Status</th><th>Kennung</th></tr>
		<?php foreach ($rows as $event):
			$isProbe = ($event['probe'] ?? '') !== '';
			$path = (string)($event['path'] ?? '');
			$violation = str_starts_with($path, '/admin'); ?>
			<tr class="<?= $isProbe ? 'probe' : ($violation ? 'violation' : '') ?>">
				<?php if (!$period->isSingleDay()): ?><td class="mono"><?= h(\Honeypot\Period::day((string)$event['date'])->label()) ?></td><?php endif ?>
				<td class="mono"><?= h((string)($event['time'] ?? '')) ?></td>
				<td class="mono"><?= h((string)($event['method'] ?? '')) ?></td>
				<td class="mono"><?= h($isProbe ? (string)($event['request'] ?? '') : $path) ?>
					<?php if (($event['group'] ?? '') !== ''): ?><span class="tag bad"><?= h((string)$event['group']) ?></span><?php endif ?>
					<?php if ($isProbe): ?><span class="tag warn"><?= h((string)$event['probe']) ?></span><?php endif ?>
				</td>
				<td class="num"><?= h((string)($event['status'] ?? '')) ?></td>
				<td class="mono"><?= (string)($event['agent'] ?? '-') === '-' ? '<em>keine</em>' : h(substr((string)$event['agent'], 0, 60)) ?></td>
			</tr>
		<?php endforeach ?>
	</table>
	<?php if ($rows === []): ?><p class="empty">Nichts, was auf diesen Filter passt.</p><?php endif ?>

<?php endif ?>
</div>
