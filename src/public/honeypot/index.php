<?php
declare(strict_types=1);

/**
 * Honigtopf-Ansicht: Angriffe und Scans eines Tages, mit Detailansicht.
 *
 * Die Seite rechnet nichts selbst. Sie liest die Tagesberichte, die honeypot/analyse.php
 * als root erzeugt und unter private/honeypot/<host>/<datum>.json ablegt – die Logs
 * selbst gehören root und sollen für den Webserver unlesbar bleiben.
 *
 * Es gibt bewusst keine Kennzahl auf Basis der Client-Adresse: Vor diesem Rechner sitzt
 * eine Adressumsetzung, jede Anfrage von aussen erscheint als 10.200.0.1. Unterschieden
 * wird nach Verhalten – Werkzeug, gesuchte Pfade, Zeitmuster, Protokolltreue.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-25 12:30
 */

require __DIR__ . '/bootstrap.php';

use Honeypot\DayReport;
use Honeypot\PathTrend;
use Honeypot\Peer;
use Honeypot\ReportStore;
use Honeypot\Suggestions;

/** Ausgabe maskieren – jeder Wert stammt aus einem Log und ist damit Fremdtext. */
function h(?string $value): string
{
	return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Deutscher Name eines Ländercodes. Nur die geläufigen; alles andere bleibt beim Code,
 * der ist eindeutig. Eine vollständige Liste hiesse eine weitere Datei, die gepflegt
 * werden will – oder eine Anfrage ins Netz, und die macht diese Seite nicht.
 */
function country_name(string $code): string
{
	static $names = [
		'DE' => 'Deutschland', 'AT' => 'Österreich', 'CH' => 'Schweiz', 'NL' => 'Niederlande',
		'FR' => 'Frankreich', 'GB' => 'Vereinigtes Königreich', 'IE' => 'Irland', 'BE' => 'Belgien',
		'LU' => 'Luxemburg', 'PL' => 'Polen', 'CZ' => 'Tschechien', 'SE' => 'Schweden',
		'FI' => 'Finnland', 'NO' => 'Norwegen', 'DK' => 'Dänemark', 'ES' => 'Spanien',
		'PT' => 'Portugal', 'IT' => 'Italien', 'RO' => 'Rumänien', 'BG' => 'Bulgarien',
		'UA' => 'Ukraine', 'RU' => 'Russland', 'TR' => 'Türkei', 'US' => 'USA', 'CA' => 'Kanada',
		'BR' => 'Brasilien', 'MX' => 'Mexiko', 'AR' => 'Argentinien', 'CN' => 'China',
		'HK' => 'Hongkong', 'TW' => 'Taiwan', 'JP' => 'Japan', 'KR' => 'Südkorea', 'IN' => 'Indien',
		'SG' => 'Singapur', 'VN' => 'Vietnam', 'ID' => 'Indonesien', 'TH' => 'Thailand',
		'AU' => 'Australien', 'NZ' => 'Neuseeland', 'ZA' => 'Südafrika', 'IR' => 'Iran',
		'IL' => 'Israel', 'AE' => 'Vereinigte Arabische Emirate', 'SC' => 'Seychellen',
		'PA' => 'Panama', 'BZ' => 'Belize', 'EU' => 'Europa (ohne Land)', 'AP' => 'Asien/Pazifik (ohne Land)',
	];
	return $names[strtoupper($code)] ?? strtoupper($code);
}

/** Ein Parameter aus der Adresszeile, als Zeichenkette. */
function param(string $name, string $default = ''): string
{
	$value = $_GET[$name] ?? $default;
	return is_string($value) ? $value : $default;
}

/** Adresse innerhalb der Ansicht, mit geänderten Parametern. */
function link_to(array $changes): string
{
	$query = array_filter(array_merge([
		'host' => param('host'),
		'tag' => param('tag'),
		'ansicht' => param('ansicht'),
	], $changes), static fn($v): bool => $v !== '' && $v !== null);
	return '?' . http_build_query($query);
}

/** Eine Balkenzeile. */
function bars(array $values, int $max, string $tone = '', bool $mono = false): string
{
	if ($values === []) {
		return '<p class="empty">Nichts an diesem Tag.</p>';
	}
	$out = '<div class="bars">';
	foreach ($values as $key => $count) {
		$width = $max > 0 ? max(1, (int)round((int)$count / $max * 100)) : 0;
		$out .= '<div class="key' . ($mono ? ' mono' : '') . '" title="' . h((string)$key) . '">'
			. h((string)$key === '' ? '(leer)' : (string)$key) . '</div>'
			. '<div class="track"><div class="fill ' . $tone . '" style="width:' . $width . '%"></div></div>'
			. '<div class="num">' . (int)$count . '</div>';
	}
	return $out . '</div>';
}

// --- Daten finden -----------------------------------------------------------
// Die Berichte liegen ausserhalb des Docroots (private/), aber innerhalb von
// open_basedir – siehe bootstrap.php.
$private = honeypot_private_dir();
$dataDir = $private === '' ? '' : $private . '/honeypot';

$store = new ReportStore($dataDir);
$hosts = $dataDir === '' ? [] : $store->hosts();
$host = param('host');
if (!in_array($host, $hosts, true)) {
	$host = $hosts[0] ?? '';
}
$days = $host === '' ? [] : $store->days($host);
$day = param('tag');
if (!in_array($day, $days, true)) {
	$day = $days[0] ?? '';
}
$report = $host !== '' && $day !== '' ? $store->load($host, $day) : null;
$previous = null;
if ($report !== null) {
	$index = array_search($day, $days, true);
	$earlier = is_int($index) ? ($days[$index + 1] ?? null) : null;
	$previous = $earlier !== null ? $store->load($host, $earlier) : null;
}
// Verlauf für den Pfadvergleich: der gewählte Tag und bis zu 14 Tage davor. Nicht
// „die letzten 14 Tage" schlechthin – wer einen älteren Tag aufruft, will wissen, was
// DAMALS neu war.
$trend = null;
if ($report !== null) {
	$history = [$report];
	foreach (array_slice($days, (int)array_search($day, $days, true) + 1, 14) as $earlierDay) {
		$loaded = $store->load($host, $earlierDay);
		if ($loaded !== null) {
			$history[] = $loaded;
		}
	}
	$trend = new PathTrend($history);
}
$view = param('ansicht');
$views = ['pfade', 'verlauf', 'kennungen', 'dienste', 'anmeldungen', 'herkunft', 'ereignisse'];
if (!in_array($view, $views, true)) {
	$view = '';
}
$titles = [
	'pfade' => 'Gesuchte Pfade',
	'verlauf' => 'Pfade über Tage',
	'herkunft' => 'Herkunft',
	'kennungen' => 'Kennungen',
	'dienste' => 'Kein Webzugriff',
	'anmeldungen' => 'Anmeldeversuche',
	'ereignisse' => 'Ereignisse',
];
$title = $view === '' ? 'Honigtopf' : $titles[$view];
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> · <?= h($host !== '' ? $host : 'ohne Daten') ?></title>
<link rel="stylesheet" href="style.css">
</head>
<body>

<header class="top">
	<a class="name" href="?"><span class="hive">&#9679;</span> Honigtopf</a>
	<span class="where"><?= h($host !== '' ? $host : 'keine Berichte') ?></span>
	<span class="spacer"></span>
	<?php if ($days !== []): ?>
	<form method="get">
		<?php if (count($hosts) > 1): ?>
			<label>Host
				<select name="host" onchange="this.form.submit()">
					<?php foreach ($hosts as $option): ?>
						<option value="<?= h($option) ?>" <?= $option === $host ? 'selected' : '' ?>><?= h($option) ?></option>
					<?php endforeach ?>
				</select>
			</label>
		<?php else: ?>
			<input type="hidden" name="host" value="<?= h($host) ?>">
		<?php endif ?>
		<label>Tag
			<select name="tag" onchange="this.form.submit()">
				<?php foreach ($days as $option): ?>
					<option value="<?= h($option) ?>" <?= $option === $day ? 'selected' : '' ?>><?= h($option) ?></option>
				<?php endforeach ?>
			</select>
		</label>
		<?php if ($view !== ''): ?><input type="hidden" name="ansicht" value="<?= h($view) ?>"><?php endif ?>
		<noscript><button>Zeigen</button></noscript>
	</form>
	<?php endif ?>
</header>

<main class="work">
<?php if ($report === null): ?>

	<div class="tile">
		<h2>Noch keine Berichte</h2>
		<p class="note">Unter <span class="mono"><?= h($dataDir !== '' ? $dataDir : 'private/honeypot') ?></span>
			liegt kein Tagesbericht. Die Auswertung läuft täglich als eigener Dienst
			(<span class="mono">vhost-admin-honeypot.timer</span>); ein erster Lauf von Hand:</p>
		<p class="mono">sudo php /opt/vhost-admin/honeypot/analyse.php --dashboard=&lt;dieser Host&gt; &lt;Honigtopf-Host&gt;</p>
	</div>

<?php elseif ($view === ''): $suggestions = (new Suggestions())->forReport($report); ?>

	<div class="tiles">

		<div class="tile">
			<h2>Tagesbilanz<?= $report->complete ? '' : ' <span class="tag warn">läuft noch</span>' ?></h2>
			<div class="figures">
				<div class="figure">
					<div class="value"><?= $report->requests ?></div>
					<div class="label">Anfragen</div>
				</div>
				<div class="figure">
					<div class="value bad"><?= $report->probing() ?></div>
					<div class="label">Sondierungen (404)</div>
				</div>
				<div class="figure">
					<div class="value warn"><?= $report->probeCount ?></div>
					<div class="label">kein Webzugriff</div>
				</div>
			</div>
			<?php if ($previous !== null && $previous->requests > 0): ?>
				<p class="delta">Vortag (<?= h($previous->date) ?>): <?= $previous->requests ?> Anfragen,
					<?= $previous->probing() ?> Sondierungen
					(<?= sprintf('%+d %%', (int)round(($report->requests - $previous->requests) / $previous->requests * 100)) ?>).</p>
			<?php endif ?>
			<p class="hint">Die Zahl, auf die es ankommt, ist <strong>404</strong>: Sie misst die Sondierung
				direkt. Gesucht wurde etwas, das es hier nie gab.</p>
		</div>

		<div class="tile">
			<h2>Tagesverlauf</h2>
			<?php $peak = max($report->hours ?: [0]) ?: 1; ?>
			<div class="hours">
				<?php foreach ($report->hours as $hour => $count): ?>
					<div class="h" title="<?= h((string)$hour) ?>:00 – <?= (int)$count ?> Anfragen">
						<div class="f" style="height:<?= (int)round((int)$count / $peak * 100) ?>%"></div>
					</div>
				<?php endforeach ?>
			</div>
			<div class="scale"><span>00</span><span>06</span><span>12</span><span>18</span><span>23</span></div>
			<p class="hint">Die Aussage ist die <strong>Form</strong>, nicht die Höhe: Wellen mit Ruhe dazwischen
				sind kein Publikum, sondern ein Zeitplan.</p>
		</div>

		<div class="tile">
			<h2>Neu gesucht <a href="<?= h(link_to(['ansicht' => 'verlauf'])) ?>">Verlauf &rarr;</a></h2>
			<?php if ($trend === null || !$trend->hasHistory()): ?>
				<p class="empty">Noch kein Vortag zum Vergleichen.</p>
			<?php else: $fresh = $trend->newToday(); ?>
				<div class="figures">
					<div class="figure">
						<div class="value <?= $fresh === [] ? '' : 'bad' ?>"><?= count($fresh) ?></div>
						<div class="label">Pfade, die in <?= count($trend->dates()) - 1 ?> Vortagen niemand suchte</div>
					</div>
				</div>
				<?php if ($fresh !== []): ?>
					<?= bars(array_slice($fresh, 0, 6, true), (int)max($fresh), 'bad', true) ?>
				<?php endif ?>
			<?php endif ?>
			<p class="hint">Die nützlichste Frühwarnung dieser Seite: Ein Pfad, der plötzlich auftaucht, ist
				meist eine frisch bekannt gewordene Lücke, die gerade reihum ausprobiert wird.</p>
		</div>

		<div class="tile">
			<h2>Wonach gesucht wird <a href="<?= h(link_to(['ansicht' => 'pfade'])) ?>">alle Pfade &rarr;</a></h2>
			<?php $loot = array_filter($report->loot); arsort($loot); ?>
			<?= bars($loot, (int)(max($loot ?: [0]) ?: 1), 'bad') ?>
			<p class="hint">Die Gruppe ist die Aussage, nicht der einzelne Pfad.</p>
		</div>

		<div class="tile">
			<h2>Womit <a href="<?= h(link_to(['ansicht' => 'kennungen'])) ?>">alle Kennungen &rarr;</a></h2>
			<?php $classes = array_filter($report->agentClasses()); ?>
			<?= bars($classes, (int)(max($classes ?: [0]) ?: 1), 'warn') ?>
			<?php if ($report->rotating !== []): ?>
				<table class="compact">
					<?php foreach ($report->rotating as $count => $list): ?>
						<tr>
							<td class="num"><?= count($list) ?>&times;</td>
							<td>Kennungen mit je genau <strong><?= (int)$count ?></strong> Anfragen –
								<span class="mono"><?= h(substr((string)$list[0], 0, 48)) ?>…</span></td>
						</tr>
					<?php endforeach ?>
				</table>
			<?php endif ?>
			<p class="hint">„Tarnt sich“ sind Kennungen, die <em>genau gleich oft</em> vorkamen – ein Werkzeug,
				das durchwechselt. Das erkennt auch die, die auf keiner Bot-Liste stehen.</p>
		</div>

		<div class="tile">
			<h2>robots.txt-Signal <a href="<?= h(link_to(['ansicht' => 'ereignisse', 'was' => 'falle'])) ?>">Verläufe &rarr;</a></h2>
			<div class="figures">
				<div class="figure"><div class="value"><?= $report->robots ?></div><div class="label">robots.txt gelesen</div></div>
				<div class="figure"><div class="value"><?= $report->admin ?></div><div class="label">/admin besucht</div></div>
				<div class="figure"><div class="value bad"><?= $report->robotsThenAdmin ?></div><div class="label">davon danach</div></div>
			</div>
			<p class="hint">
				<?php if ($report->shortestGap() !== null): ?>
					Kürzester Abstand: <strong><?= (int)$report->shortestGap() ?> s</strong>.
				<?php endif ?>
				Die <span class="mono">robots.txt</span> schliesst <span class="mono">/admin/</span> ausdrücklich
				aus. Ein Besuch danach ist ein bewusster Verstoss; einer ohne ist blosses Raten.</p>
		</div>

		<div class="tile">
			<h2>Kein Webzugriff <a href="<?= h(link_to(['ansicht' => 'dienste'])) ?>">Rohdaten &rarr;</a></h2>
			<?= bars($report->probeKinds, (int)(max($report->probeKinds ?: [0]) ?: 1), 'warn') ?>
			<?php if ($report->probes !== []): ?>
				<table class="compact">
					<?php foreach (array_slice($report->probes, 0, 4, true) as $raw => $count): ?>
						<tr><td class="num"><?= (int)$count ?></td><td class="mono"><?= h(substr((string)$raw, 0, 56)) ?></td></tr>
					<?php endforeach ?>
				</table>
			<?php endif ?>
			<p class="hint">Gesucht wurde nicht nach Webinhalten, sondern nach Diensten: SSH auf Port 443,
				ein offener Proxy, ein Binärprotokoll. Bei gewöhnlichen Auswertungen fällt das hinten runter.</p>
		</div>

		<div class="tile">
			<h2>Anmeldeversuche <a href="<?= h(link_to(['ansicht' => 'anmeldungen'])) ?>">alle &rarr;</a></h2>
			<?php $logins = array_slice($report->logins, 0, 6, true); ?>
			<?= bars($logins, (int)(max($report->logins ?: [0]) ?: 1), 'bad', true) ?>
			<p class="hint">Die Namen sind aufschlussreicher als ihre Zahl: <span class="mono">admin</span> und
				<span class="mono">root</span> sind geraten, der Domainname ist gezielt.</p>
		</div>

		<div class="tile">
			<h2>Woher <a href="<?= h(link_to(['ansicht' => 'herkunft'])) ?>">alle Gegenstellen &rarr;</a></h2>
			<?php
				$countries = $report->countries();
				$networks = $report->networks();
				$connected = count(array_filter($report->peers, static fn(Peer $p): bool => $p->kind === Peer::CONNECTION));
			?>
			<?php if ($report->peers === []): ?>
				<p class="empty">An diesem Tag hat sich keine Gegenstelle zu erkennen gegeben.</p>
			<?php else: ?>
				<?php $named = []; foreach ($countries as $code => $count) { $named[country_name((string)$code) . ' (' . $code . ')'] = $count; } ?>
				<?= bars(array_slice($named, 0, 6, true), (int)(max($countries ?: [0]) ?: 1)) ?>
				<?php if ($networks !== []): ?>
					<p class="hint" style="margin-top:.5rem">Netze:
						<?php foreach (array_slice($networks, 0, 5, true) as $net => $count): ?>
							<span class="mono"><?= h((string)$net) ?></span> <span class="tag"><?= (int)$count ?></span>
						<?php endforeach ?>
					</p>
				<?php endif ?>
			<?php endif ?>
			<p class="hint">
				<?php if ($connected > 0): ?>
					Aus der tatsächlichen Verbindungsadresse.
				<?php else: ?>
					Aus <strong>Selbstauskünften</strong>: der URL, mit der ein Scanner sich ausweist, dem Ziel eines
					Proxy-Versuchs, dem Ablageserver eines Exploits. Die Verbindungsadresse selbst geht im Tunnel
					verloren. Vorsicht beim Lesen: Liegt die Webseite eines Betreibers hinter einem CDN (Cloudflare,
					Akamai, GitHub), steht hier das Land des CDN – nicht das des Scanners. Land und Netz nach Zuteilung
					der Registry, nicht nach Standort.
				<?php endif ?>
			</p>
		</div>

		<div class="tile">
			<h2>Meistgesuchte Pfade <a href="<?= h(link_to(['ansicht' => 'pfade'])) ?>">alle &rarr;</a></h2>
			<?php $top = array_slice($report->notFound, 0, 8, true); ?>
			<?= bars($top, (int)(max($report->notFound ?: [0]) ?: 1), '', true) ?>
		</div>

		<div class="tile wide">
			<h2>Was sich als Nächstes lohnt</h2>
			<ul class="suggestions">
				<?php foreach ($suggestions as $suggestion): ?>
					<li><?= preg_replace('/`([^`]*)`/', '<span class="mono">$1</span>',
						preg_replace('/\*\*([^*]*)\*\*/', '<strong>$1</strong>', h($suggestion))) ?></li>
				<?php endforeach ?>
			</ul>
			<p class="hint">Abgeleitet aus den Zahlen dieses Tages – ein Vorschlag erscheint nur, wenn die
				Daten ihn tragen.</p>
		</div>

		<div class="tile wide">
			<h2>Was diese Ansicht nicht zeigt</h2>
			<p class="note">Solange der Verkehr durch den WireGuard-Tunnel mit Adressumsetzung kommt, gibt es
				<strong>keine Verbindungsadresse</strong>: Jede Anfrage erscheint im Log als
				<span class="mono">10.200.0.1</span>. „Eindeutige Besucher“ oder Top-Angreifer nach Adresse wären
				deshalb erfunden. Die Kachel „Woher“ stützt sich auf das, was Anfragen selbst nennen; sobald die
				echte Adresse ankommt, nimmt sie die, ohne dass hier etwas umgestellt werden muss.
				<?php if ($report->unreadable > 0): ?>
					<br><?= $report->unreadable ?> Logzeilen liessen sich nicht lesen.
				<?php endif ?>
			</p>
		</div>

	</div>

<?php else: require __DIR__ . '/detail.php'; endif ?>
</main>
</body>
</html>
