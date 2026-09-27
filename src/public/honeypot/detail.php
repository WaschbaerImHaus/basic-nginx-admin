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
 * @version Letzte Änderung: 2026-09-27 13:45
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

	<?php $history = $db->loginHistory($host); ?>
	<?php if ($history !== []): ?>
		<h2 style="margin-top:1.4rem">Alle Namen seit Beginn der Aufzeichnung</h2>
		<table>
			<tr><th class="num">Versuche</th><th>Benutzername</th><th>Einordnung</th><th>zuerst</th><th>zuletzt</th><th class="num">an Tagen</th></tr>
			<?php foreach ($history as $row): $class = \Honeypot\LoginName::classify($row['user'], $host); ?>
				<tr>
					<td class="num"><?= $row['total'] ?></td>
					<td class="mono"><?= $row['user'] === '' ? '<em>(leer)</em>' : h($row['user']) ?></td>
					<td><span class="tag <?= $class === \Honeypot\LoginName::DECOY ? 'bad' : ($class === \Honeypot\LoginName::TARGETED ? 'warn' : '') ?>"><?= h($class) ?></span></td>
					<td class="mono"><?= h(\Honeypot\Period::day($row['first'])->label()) ?></td>
					<td class="mono"><?= h(\Honeypot\Period::day($row['last'])->label()) ?></td>
					<td class="num"><?= $row['days'] ?></td>
				</tr>
			<?php endforeach ?>
		</table>
		<p class="hint"><em>Geraten</em>: Namen, die Werkzeuge überall probieren (admin, root, test).
			<em>Gezielt</em>: Der Name stammt aus dieser Domain – jemand hat sich die Seite angesehen.
			<em>Aus dem Köder</em>: Der Name steht nur in einem ausgelieferten Köder; der Fund wurde benutzt.
			Ein Name, der über Wochen an vielen Tagen wiederkehrt, gehört zu einer festen Liste.</p>
	<?php endif ?>

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

<?php elseif ($view === 'koeder'): $activity = $db->decoyActivity($host, $period); ?>

	<h2>Köder und ihre Kennungen</h2>
	<p class="hint" style="margin-top:0"><?= $activity['issued'] ?> Kennungen <?= h($span) ?> ausgegeben,
		<?= count($activity['uses']) ?> Benutzungen <?= h($span) ?>.</p>
	<?php if ($activity['uses'] === []): ?>
		<p class="empty">Keine ausgegebene Kennung ist bisher wieder aufgetaucht.</p>
	<?php else: ?>
	<table>
		<tr><th>benutzt</th><th>wie</th><th>womit</th><th>Kennung</th><th>ausgegeben</th><th>für</th><th class="num">Abstand</th></tr>
		<?php foreach ($activity['uses'] as $use): ?>
			<tr class="violation">
				<td class="mono"><?= h(\Honeypot\Period::day((string)$use['date'])->label() . ' ' . $use['time']) ?></td>
				<td><span class="tag bad"><?= h((string)$use['source']) ?></span></td>
				<td class="mono"><?= h((string)$use['detail']) ?><?= $use['agent'] !== '' ? '<br><em>' . h(substr((string)$use['agent'], 0, 60)) . '</em>' : '' ?></td>
				<td class="mono"><?= h((string)$use['token']) ?></td>
				<td class="mono"><?= h((string)$use['issued_at']) ?></td>
				<td class="mono"><?= h((string)$use['issued_request']) ?><br><em><?= h(substr((string)$use['issued_agent'], 0, 60)) ?></em></td>
				<td class="num"><?= h(duration((int)$use['delay'])) ?></td>
			</tr>
		<?php endforeach ?>
	</table>
	<?php endif ?>
	<p class="hint"><strong>So funktionieren die Köder.</strong> Anfragen nach <span class="mono">phpinfo.php</span>
		(in allen gefragten Schreibweisen und Unterordnern), <span class="mono">.env</span>,
		<span class="mono">wp-config.php</span> samt Sicherungskopien, <span class="mono">config.php</span>,
		<span class="mono">/server-status</span>, <span class="mono">/server-info</span> und Verzeichnisausbrüche nach
		<span class="mono">.aws/credentials</span> beantwortet der Honigtopf mit einer erfundenen, echt wirkenden Fassung. Darin stehen ein Benutzername
		<span class="mono">deploy-&lt;kennung&gt;</span> und zwei interne Adressen mit derselben Kennung; die Kennung
		ist für jeden Abruf neu. Taucht sie später im Log auf, ist belegt, dass der Fund ausgewertet und benutzt
		wurde – und von welchem Abruf er stammt. Alle Werte sind erfunden und gelten nirgends; mitgeschrieben wird
		nichts, was ein Besucher eingibt.</p>

<?php elseif ($view === 'wiederkehrer'): $families = families_in($db, $host, $period); ?>

	<h2>Scanner-Familien</h2>
	<p class="hint" style="margin-top:0"><?= count($families) ?> Familien mit Sitzungen <?= h($span) ?>; verglichen wird mit
		den 90 Tagen bis zum Ende des Zeitraums. Eine Sitzung sind die Anfragen einer Kennung (oder einer Gruppe
		durchgewechselter Kennungen) ohne Pause über 30 Minuten, mit mindestens drei Anfragen auf zwei Pfade.</p>
	<?php if ($families === []): ?>
		<p class="empty">Keine Sitzungen – noch zu wenig Verkehr oder nur Einzelanfragen.</p>
	<?php else: ?>
	<table>
		<tr><th class="num">Tage</th><th>zuerst – zuletzt</th><th>Zeit</th><th class="num">Sitzungen</th><th class="num">Anfragen</th><th>Kennungen</th><th>Kern der Wortliste</th></tr>
		<?php foreach ($families as $family): $dates = $family->dates(); $agents = $family->agents(); $common = $family->commonPaths(); ?>
			<tr class="<?= $family->recurring() ? 'probe' : '' ?>">
				<td class="num"><?= count($dates) ?></td>
				<td class="mono"><?= h(\Honeypot\Period::day($dates[0])->label()) ?><?= count($dates) > 1 ? ' – ' . h(\Honeypot\Period::day(end($dates))->label()) : '' ?></td>
				<td><?= h($family->timePattern()) ?></td>
				<td class="num"><?= count($family->sessions) ?></td>
				<td class="num"><?= $family->requests() ?></td>
				<td class="mono"><?= count($agents) > 1 ? '<span class="tag warn">' . count($agents) . ' Kennungen</span><br>' : '' ?><?= h(substr((string)$agents[0], 0, 60)) ?><?= count($agents) > 1 ? ' …' : '' ?></td>
				<td class="mono"><?= h(implode(' ', array_slice($common, 0, 12))) ?><?= count($common) > 12 ? ' … (' . count($common) . ')' : '' ?></td>
			</tr>
		<?php endforeach ?>
	</table>
	<?php endif ?>
	<p class="hint"><strong>Warum Wortliste statt Kennung.</strong> Vor dem Honigtopf sitzt eine Adressumsetzung – jede
		Anfrage kommt als 10.200.0.1. Kennungen wechseln Werkzeuge gern, die Liste der Pfade, die sie abklappern, dagegen
		selten. Kommt eine Familie immer zur selben Uhrzeit, läuft sie nach Zeitplan.</p>

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
