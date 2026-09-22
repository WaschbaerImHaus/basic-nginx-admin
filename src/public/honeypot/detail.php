<?php
declare(strict_types=1);

/**
 * Detailansichten der Honigtopf-Ansicht: die vollständigen Listen hinter den Kacheln.
 *
 * Wird ausschliesslich von index.php eingebunden; dort sind $report, $host, $day und
 * $view bereits geprüft. Eigene Datei, damit die Übersicht lesbar bleibt.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-22 18:30
 */

/** @var \Honeypot\DayReport $report */
/** @var string $view */
/** @var string $host */
/** @var string $day */

$classifier = new \Honeypot\LootClassifier();
?>
<p class="crumb"><a href="<?= h(link_to(['ansicht' => ''])) ?>">&larr; Übersicht</a>
	· <?= h($host) ?> · <?= h($day) ?></p>

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
		<?php foreach ($report->probes as $raw => $count):
			$sample = null;
			foreach ($report->events as $event) {
				if (($event['probe'] ?? '') !== '' && str_starts_with((string)($event['request'] ?? ''), (string)$raw)) {
					$sample = (string)$event['probe'];
					break;
				}
			} ?>
			<tr class="probe">
				<td class="num"><?= (int)$count ?></td>
				<td><span class="tag warn"><?= h($sample ?? 'unbekannt') ?></span></td>
				<td class="mono"><?= h((string)$raw) ?></td>
			</tr>
		<?php endforeach ?>
	</table>
	<?php if ($report->probes === []): ?><p class="empty">An diesem Tag keine.</p><?php endif ?>
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
	<?php if ($report->logins === []): ?><p class="empty">An diesem Tag keine.</p><?php endif ?>
	<p class="hint">Aus dem <span class="mono">error.log</span>, solange der Host einen Verzeichnisschutz
		trägt. Passwörter stehen dort nicht und werden hier auch nicht gesammelt – wer Zugangsdaten
		einsammelt, betreibt keinen Honigtopf mehr.</p>

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
	$matches = static function (array $event) use ($what, $needle): bool {
		$path = (string)($event['path'] ?? '');
		$hit = match ($what) {
			'sondierung' => ($event['status'] ?? '') === '404',
			'dienst' => ($event['probe'] ?? '') !== '',
			'falle' => $path === '/robots.txt' || str_starts_with($path, '/admin'),
			default => true,
		};
		if (!$hit || $needle === '') {
			return $hit;
		}
		$haystack = $path . ' ' . ($event['agent'] ?? '') . ' ' . ($event['request'] ?? '');
		return stripos($haystack, $needle) !== false;
	};
	$rows = array_values(array_filter($report->events, $matches));
	$limit = 500;
?>

	<h2>Ereignisse</h2>
	<div class="filters">
		<?php foreach ($filters as $key => $label): ?>
			<a class="tag <?= $what === $key ? 'ok' : '' ?>" href="<?= h(link_to(['was' => $key, 'q' => $needle])) ?>"><?= h($label) ?></a>
		<?php endforeach ?>
		<form method="get" style="display:flex;gap:.4rem">
			<input type="hidden" name="host" value="<?= h($host) ?>">
			<input type="hidden" name="tag" value="<?= h($day) ?>">
			<input type="hidden" name="ansicht" value="ereignisse">
			<input type="hidden" name="was" value="<?= h($what) ?>">
			<input type="search" name="q" value="<?= h($needle) ?>" placeholder="Pfad oder Kennung">
			<button>Suchen</button>
		</form>
	</div>
	<p class="hint" style="margin-top:0"><?= count($rows) ?> von <?= count($report->events) ?> auffälligen
		Anfragen<?= count($rows) > $limit ? ', gezeigt werden die ersten ' . $limit : '' ?>. Gewöhnliche
		Treffer stehen bewusst nicht in der Liste.</p>
	<table>
		<tr><th>Zeit</th><th>Verb</th><th>Pfad bzw. Anfragezeile</th><th class="num">Status</th><th>Kennung</th></tr>
		<?php foreach (array_slice($rows, 0, $limit) as $event):
			$isProbe = ($event['probe'] ?? '') !== '';
			$path = (string)($event['path'] ?? '');
			$violation = str_starts_with($path, '/admin'); ?>
			<tr class="<?= $isProbe ? 'probe' : ($violation ? 'violation' : '') ?>">
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
