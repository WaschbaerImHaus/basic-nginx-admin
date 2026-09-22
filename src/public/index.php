<?php
declare(strict_types=1);

/**
 * Verwaltungsoberfläche (Docroot /var/www/localhost-8080, nur 127.0.0.1:8080).
 *
 * Diese Datei ist das Template; alle Logik liegt in VhostAdmin\Web\AdminPage.
 *
 * Aufbau: links eine dauerhafte Liste aller vHosts mit ihrem Zustand, rechts der
 * Arbeitsbereich über die volle Fensterbreite. Maschinenwerte (Domains, Pfade,
 * Direktiven) stehen durchgehend in Festbreitenschrift – sie sind der eigentliche
 * Inhalt dieser Oberfläche. Schriften kommen ausschliesslich vom System: eine lokale
 * Verwaltungsoberfläche soll keine Anfragen ins Netz auslösen.
 *
 * @author Kurt Ingwer
 * @version Letzte Änderung: 2026-09-21 09:20
 */

$bootstrap = is_file('/opt/vhost-admin/bootstrap.php') ? '/opt/vhost-admin/bootstrap.php' : dirname(__DIR__) . '/bootstrap.php';
require $bootstrap;

use VhostAdmin\Config;
use VhostAdmin\Database;
use VhostAdmin\VhostLayout;
use VhostAdmin\VhostRepository;
use VhostAdmin\Ssl\CurlAcmeReachability;
use VhostAdmin\Ssl\ReachabilityResult;
use VhostAdmin\Ssl\ReachabilityStatus;
use VhostAdmin\Ssl\ReachabilityChecker;
use VhostAdmin\Vhost;
use VhostAdmin\Web\AdminPage;
use VhostAdmin\Web\CommandRunner;

session_start();
$config = Config::defaults();
$layout = new VhostLayout($config);
$page = new AdminPage(
	new VhostRepository(new Database($config)),
	new CommandRunner($config),
	$config,
	$layout,
	new ReachabilityChecker(new CurlAcmeReachability()),
	$_SESSION
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!$page->isValidCsrf((string)($_POST['csrf'] ?? ''))) {
		http_response_code(403);
		exit('Ungültiges Formular-Token.');
	}
	set_time_limit(180);
	header('Location: ' . $page->handlePost($_POST));
	exit;
}

$csrf = $page->csrfToken();
$flash = $page->takeFlash();
$email = $page->letsEncryptEmail();
$view = isset($_GET['v']) ? $page->vhost((string)$_GET['v']) : null;
if (isset($_GET['v']) && $view === null) {
	http_response_code(404);
}

// Einmal für alle Hosts prüfen: die Liste links und die Detailansicht brauchen
// dieselben Ergebnisse, und die Abfragen laufen ohnehin parallel.
$vhosts = $page->vhosts();
$reachable = $page->reachability($vhosts);

/**
 * HTML-Escaping für Ausgaben.
 */
function h(mixed $value): string
{
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Kleines Inline-Formular mit einem Button (Aktion + versteckte Felder).
 *
 * @param array<string, string> $fields
 */
function form(string $action, array $fields, string $label, string $class = '', string $confirm = ''): string
{
	global $csrf;
	$html = '<form method="post" class="inline"' . ($confirm !== '' ? ' onsubmit="return confirm(' . h(json_encode($confirm)) . ')"' : '') . '>';
	$html .= '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="action" value="' . h($action) . '">';
	foreach ($fields as $key => $value) {
		$html .= '<input type="hidden" name="' . h($key) . '" value="' . h($value) . '">';
	}
	return $html . '<button class="' . h($class) . '">' . h($label) . '</button></form>';
}

/**
 * Zustandsmarke. $tone: ok, off, bad, warn, local.
 */
function mark(string $tone, string $text, string $title = ''): string
{
	return '<span class="mark ' . h($tone) . '"' . ($title !== '' ? ' title="' . h($title) . '"' : '') . '>' . h($text) . '</span>';
}

/**
 * Zustandsmarke für an/aus.
 */
function badge(bool $on, string $yes, string $no): string
{
	return mark($on ? 'ok' : 'off', $on ? $yes : $no);
}

/**
 * Erreichbarkeit: grün, wenn Let's Encrypt den ACME-Pfad erreichen kann, sonst rot.
 * "entfällt" bleibt neutral – ein localhost-Host soll nicht erreichbar sein.
 */
function reach(?ReachabilityResult $r): string
{
	if ($r === null) {
		return '<span class="dash">–</span>';
	}
	$tone = match ($r->status) {
		ReachabilityStatus::Ok => 'ok',
		ReachabilityStatus::NotApplicable => 'local',
		default => 'bad',
	};
	return mark($tone, $r->status->label(), $r->message);
}

/**
 * Kurzform des Zustands für die Liste links: die drei Dinge, die man im Blick
 * behalten will – ist er erreichbar, ist er geschützt, läuft PHP.
 */
function railState(Vhost $v, ?ReachabilityResult $r): string
{
	$out = '';
	if ($v->isPendingDeletion()) {
		return mark('bad', 'wird entfernt');
	}
	if (!$v->isLocal()) {
		$out .= reach($r);
		$out .= $v->ssl ? mark('ok', 'https') : mark('off', 'http');
	} else {
		$out .= mark('local', 'lokal');
	}
	$out .= $v->protect ? mark('warn', 'geschützt') : mark('off', 'offen');
	if ($v->php) {
		$out .= mark('ok', 'php');
	}
	return $out;
}

/**
 * Konfigurationstext mit Zeilennummern ausgeben.
 *
 * Hervorgehoben wird nur, was beim Lesen hilft: Kommentare treten zurück, der eigene
 * Abschnitt zwischen den Markern wird hinterlegt – daran sieht man auf einen Blick,
 * wo die eigenen Direktiven landen.
 */
function confListing(string $text): string
{
	$out = '';
	$inOwn = false;
	foreach (explode("\n", rtrim($text, "\n")) as $number => $line) {
		$trimmed = ltrim($line);
		if (str_starts_with($trimmed, '# >>>>')) {
			$inOwn = true;
		}
		$classes = [];
		if ($inOwn) {
			$classes[] = 'own';
		}
		if (str_starts_with($trimmed, '#')) {
			$classes[] = 'comment';
		}
		if (str_starts_with($trimmed, '# <<<<')) {
			$inOwn = false;
		}
		$out .= '<span class="ln">' . ($number + 1) . '</span>'
			. '<span class="' . implode(' ', $classes) . '">' . h($line) . "</span>\n";
	}
	return $out;
}

// Fehlermeldungen der Direktivenprüfung nennen die Zeile ("Zeile 3: ..."). Die
// markieren wir im Editor, statt sie den Nutzer selbst suchen zu lassen.
$errorLine = 0;
if ($flash && $flash[0] === 'err' && preg_match('/Zeile (\d+)/', $flash[1], $m)) {
	$errorLine = (int)$m[1];
}
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>vhost-admin<?= $view ? ' – ' . h($view->name) : '' ?></title>
<style>
	:root {
		--ground:#eef1f5; --surface:#fff; --sunken:#e7ebf1;
		--ink:#16202b; --muted:#5e6b7a; --line:#d3dae3; --line-soft:#e4e9ef;
		--act:#2b4acb; --ok:#17734a; --warn:#8a5a00; --bad:#b3261e; --local:#4338a8;
		--mono: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, "Liberation Mono", monospace;
		--sans: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", sans-serif;
		--rail: 19rem;
	}
	@media (prefers-color-scheme: dark) {
		:root {
			--ground:#10151b; --surface:#171d25; --sunken:#0c1117;
			--ink:#dde4ec; --muted:#8d9aab; --line:#2a333f; --line-soft:#222a34;
			--act:#8ea4ff; --ok:#57c88d; --warn:#d9a441; --bad:#ef7a72; --local:#a6a1ff;
		}
	}
	* { box-sizing:border-box; }
	html, body { height:100%; }
	body {
		margin:0; background:var(--ground); color:var(--ink);
		font:15px/1.55 var(--sans);
		display:grid; grid-template-rows:auto 1fr; min-height:100%;
	}
	a { color:var(--act); }
	code, .mono { font-family:var(--mono); font-size:.92em; }

	/* ---------- Kopfzeile ---------- */
	.top {
		display:flex; align-items:center; gap:1.25rem; flex-wrap:wrap;
		padding:.7rem 1.25rem; background:var(--surface); border-bottom:1px solid var(--line);
	}
	.top .name { font-family:var(--mono); font-weight:600; font-size:1rem; letter-spacing:-.01em; text-decoration:none; color:var(--ink); }
	.top .where { color:var(--muted); font-size:.85rem; font-family:var(--mono); }
	.top .spacer { flex:1; }
	.top .setting { display:flex; align-items:center; gap:.4rem; font-size:.85rem; color:var(--muted); }

	/* ---------- Gerüst ---------- */
	.shell { display:grid; grid-template-columns:var(--rail) minmax(0,1fr); min-height:0; }
	@media (max-width:60rem) { .shell { grid-template-columns:1fr; } }

	.rail { border-right:1px solid var(--line); background:var(--surface); padding:.75rem 0 2rem; overflow:auto; }
	.rail h2 { font-size:.78rem; font-weight:600; color:var(--muted); margin:.25rem 0 .5rem; padding:0 1rem; }
	.rail .entry {
		display:block; padding:.5rem 1rem .55rem; text-decoration:none; color:inherit;
		border-left:3px solid transparent;
	}
	.rail .entry:hover { background:var(--sunken); }
	.rail .entry.active { border-left-color:var(--act); background:var(--sunken); }
	.rail .entry .host { font-family:var(--mono); font-size:.9rem; word-break:break-all; }
	.rail .entry .path { color:var(--muted); font-family:var(--mono); font-size:.74rem; word-break:break-all; }
	.rail .entry .state { margin-top:.28rem; display:flex; flex-wrap:wrap; gap:.25rem; }
	.rail .self { padding:.5rem 1rem; color:var(--muted); font-size:.8rem; border-top:1px solid var(--line-soft); margin-top:.5rem; }

	.work { padding:1.25rem; min-width:0; }

	/* ---------- Bausteine ---------- */
	.panel { background:var(--surface); border:1px solid var(--line); border-radius:6px; padding:1rem 1.15rem; margin-bottom:1rem; }
	.panel > h2 { font-size:.95rem; margin:0 0 .6rem; display:flex; align-items:center; gap:.5rem; flex-wrap:wrap; }
	.panel p { margin:.35rem 0 .6rem; max-width:68ch; }
	.hint { color:var(--muted); font-size:.87rem; }

	.cols { display:grid; grid-template-columns:repeat(auto-fit,minmax(26rem,1fr)); gap:1rem; align-items:start; }

	.mark {
		display:inline-block; padding:.05em .45em; border-radius:3px;
		font-size:.75rem; font-weight:600; font-family:var(--mono);
		border:1px solid;
	}
	.mark.ok { color:var(--ok); border-color:color-mix(in srgb, var(--ok) 35%, transparent); background:color-mix(in srgb, var(--ok) 8%, transparent); }
	.mark.bad { color:var(--bad); border-color:color-mix(in srgb, var(--bad) 35%, transparent); background:color-mix(in srgb, var(--bad) 8%, transparent); }
	.mark.warn { color:var(--warn); border-color:color-mix(in srgb, var(--warn) 35%, transparent); background:color-mix(in srgb, var(--warn) 8%, transparent); }
	.mark.local { color:var(--local); border-color:color-mix(in srgb, var(--local) 35%, transparent); background:color-mix(in srgb, var(--local) 8%, transparent); }
	.mark.off { color:var(--muted); border-color:var(--line); }
	.dash { color:var(--muted); }

	table { width:100%; border-collapse:collapse; font-size:.9rem; }
	th, td { text-align:left; padding:.45rem .5rem; border-bottom:1px solid var(--line-soft); vertical-align:middle; }
	th { color:var(--muted); font-weight:600; font-size:.8rem; }
	tr:last-child td { border-bottom:0; }
	td .mono, td code { word-break:break-all; }

	dl.facts { display:grid; grid-template-columns:max-content minmax(0,1fr); gap:.3rem 1.1rem; margin:0; font-size:.9rem; }
	dl.facts dt { color:var(--muted); }
	dl.facts dd { margin:0; font-family:var(--mono); font-size:.85rem; word-break:break-all; }

	input[type=text], input[type=password], input[type=email] {
		padding:.42rem .6rem; border:1px solid var(--line); border-radius:4px;
		background:var(--surface); color:var(--ink); font:inherit; min-width:12rem;
	}
	input:focus-visible, textarea:focus-visible, button:focus-visible, a:focus-visible {
		outline:2px solid var(--act); outline-offset:2px;
	}
	button {
		padding:.4rem .85rem; border:1px solid var(--line); border-radius:4px;
		background:var(--surface); color:var(--ink); font:inherit; cursor:pointer;
	}
	button:hover:not(:disabled) { border-color:var(--muted); }
	button.primary { background:var(--act); border-color:var(--act); color:#fff; }
	button.primary:hover { filter:brightness(1.08); }
	button.danger { color:var(--bad); border-color:color-mix(in srgb, var(--bad) 40%, transparent); }
	button.small { padding:.1rem .45rem; font-size:.8rem; }
	button:disabled { opacity:.5; cursor:not-allowed; }
	form.inline { display:inline; margin:0; }
	form.row { display:flex; flex-wrap:wrap; gap:.5rem; align-items:center; margin:0; }
	.actions { display:flex; flex-wrap:wrap; gap:.5rem; align-items:center; }

	.flash {
		padding:.7rem .9rem; border-radius:5px; margin-bottom:1rem; white-space:pre-wrap;
		font-family:var(--mono); font-size:.85rem; border:1px solid;
	}
	.flash.ok { color:var(--ok); border-color:color-mix(in srgb, var(--ok) 40%, transparent); background:color-mix(in srgb, var(--ok) 8%, transparent); }
	.flash.err { color:var(--bad); border-color:color-mix(in srgb, var(--bad) 40%, transparent); background:color-mix(in srgb, var(--bad) 8%, transparent); }

	/* ---------- Editor ---------- */
	.editor-panel { padding-bottom:.9rem; }
	.editor {
		display:grid; grid-template-columns:auto minmax(0,1fr);
		border:1px solid var(--line); border-radius:4px; overflow:hidden;
		background:var(--sunken);
		height:clamp(22rem, 58vh, 70rem);
		resize:vertical;
	}
	.editor .gutter {
		margin:0; padding:.6rem .5rem .6rem .7rem; overflow:hidden;
		background:var(--sunken); border-right:1px solid var(--line);
		color:var(--muted); text-align:right; user-select:none;
		font:13px/1.6 var(--mono);
	}
	.editor .gutter span { display:block; }
	.editor .gutter span.bad { color:var(--bad); font-weight:700; }
	.editor textarea {
		margin:0; padding:.6rem .7rem; border:0; resize:none; outline:none;
		background:var(--surface); color:var(--ink);
		font:13px/1.6 var(--mono); white-space:pre; overflow:auto; tab-size:4;
	}
	.editor-foot { display:flex; gap:.75rem; align-items:center; flex-wrap:wrap; margin-top:.6rem; }

	/* Erzeugtes Passwort: als Text lesbar (es soll ja abgeschrieben werden), aber
	   nicht überschreibbar. */
	/* Fertige Konfiguration: Zeilennummern links, Text rechts, alles scrollbar. */
	.listing {
		margin:0; border:1px solid var(--line); border-radius:4px; background:var(--surface);
		font:13px/1.6 var(--mono); overflow:auto; max-height:34rem;
		display:grid; grid-template-columns:auto minmax(0,1fr); align-content:start;
	}
	.listing .ln {
		position:sticky; left:0; padding:0 .55rem 0 .7rem; text-align:right;
		color:var(--muted); background:var(--sunken); border-right:1px solid var(--line);
		user-select:none;
	}
	.listing > span:not(.ln) { padding:0 .7rem; white-space:pre; }
	.listing .comment { color:var(--muted); }
	.listing .own { background:color-mix(in srgb, var(--act) 9%, transparent); }

	input.pw { letter-spacing:.02em; background:var(--sunken); cursor:pointer; }
	input.pw.wide { width:100%; max-width:34rem; font-size:1.05rem; padding:.55rem .7rem; }
	.panel.credential { border-color:color-mix(in srgb, var(--ok) 45%, transparent); }
	.panel.credential > h2 { color:var(--ok); }

	/* Detailansicht: Editor bekommt den grösseren Anteil der Breite, die Schalter
	   stehen daneben statt darunter. */
	.detail { display:grid; grid-template-columns:minmax(0,1.3fr) minmax(24rem,1fr); gap:1rem; align-items:start; }
	@media (max-width:78rem) { .detail { grid-template-columns:minmax(0,1fr); } }
	.detail > div { min-width:0; }

	.hostline { display:flex; align-items:baseline; gap:.75rem; flex-wrap:wrap; margin:0 0 .9rem; }
	.hostline h1 { font-family:var(--mono); font-size:1.35rem; font-weight:600; margin:0; letter-spacing:-.02em; word-break:break-all; }
	.hostline .state { display:flex; gap:.3rem; flex-wrap:wrap; }
</style>
</head>
<body>
<div class="top">
	<a class="name" href="/">vhost-admin</a>
	<span class="where">127.0.0.1:<?= h($config->adminPort) ?></span>
	<span class="spacer"></span>
	<form method="post" class="setting">
		<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="email">
		<label for="le_email">Let's-Encrypt-E-Mail</label>
		<input type="email" id="le_email" name="le_email" value="<?= h($email) ?>" placeholder="noch nicht gesetzt">
		<button>Speichern</button>
	</form>
</div>

<div class="shell">
<nav class="rail">
	<h2>vHosts</h2>
	<?php foreach ($vhosts as $v): ?>
		<a class="entry<?= $view && $v->name === $view->name ? ' active' : '' ?>" href="/?v=<?= h(rawurlencode($v->name)) ?>">
			<div class="host"><?= h($v->name) ?></div>
			<div class="path"><?= h($layout->docroot($v)) ?></div>
			<div class="state"><?= railState($v, $reachable[$v->name] ?? null) ?></div>
		</a>
	<?php endforeach ?>
	<?php if (!$vhosts): ?><p class="hint" style="padding:0 1rem">Noch kein vHost angelegt.</p><?php endif ?>
	<div class="self">Diese Oberfläche läuft selbst als vHost auf <span class="mono">localhost:<?= h($config->adminPort) ?></span>.</div>
</nav>

<main class="work">
<?php if ($flash): ?>
	<div class="flash <?= h($flash[0]) ?>"><?= h($flash[1]) ?></div>
<?php endif ?>

<?php if (isset($_GET['v']) && !$view): ?>
	<div class="panel">
		<h2>Unbekannter vHost</h2>
		<p><span class="mono"><?= h($_GET['v']) ?></span> steht nicht in der Datenbank. Links stehen die vorhandenen Hosts.</p>
	</div>

<?php elseif ($view):
	$isDomain = !$view->isLocal();
	$users = $page->users($view);
	$ips = $page->ips($view);
	$phpUser = $page->phpUser($view);
	$reach = $reachable[$view->name] ?? null;
	$url = $isDomain ? ($view->ssl ? 'https' : 'http') . '://' . $view->name . '/' : 'http://localhost:' . $view->port . '/';
?>
	<div class="hostline">
		<h1><?= h($view->name) ?></h1>
		<div class="state"><?= railState($view, $reach) ?></div>
		<a href="<?= h($url) ?>" target="_blank" rel="noopener">öffnen</a>
	</div>

	<?php if ($credential = $page->takeCredential($view)): ?>
	<div class="panel credential">
		<h2>Passwort für <span class="mono"><?= h($credential['user']) ?></span></h2>
		<p class="hint">Wird nur jetzt angezeigt. Danach ist es nicht mehr auslesbar – in der htpasswd-Datei steht nur der Hash.</p>
		<input type="text" class="mono pw wide" value="<?= h($credential['password']) ?>" readonly
			aria-label="Passwort" onclick="this.select()" autofocus>
	</div>
	<?php endif ?>

	<?php if ($view->isPendingDeletion()): $due = $view->deletionDueAt($config->removalGraceMinutes); ?>
	<div class="panel">
		<h2>Dieser vHost ist gesperrt <?= mark('bad', 'wird entfernt') ?></h2>
		<p>nginx liefert ihn nicht mehr aus. Endgültig entfernt wird er am
			<strong class="mono"><?= h($due?->format('d.m.Y H:i')) ?> UTC</strong>.
			Bis dahin lässt sich das zurücknehmen; die Dateien bleiben ohnehin erhalten.</p>
		<?= form('restore', ['name' => $view->name], 'Entfernen zurücknehmen', 'primary') ?>
	</div>
	<?php endif ?>

	<div class="detail">
	<div>
		<div class="panel editor-panel">
			<h2>Eigene nginx-Direktiven</h2>
			<p class="hint">Wird als <span class="mono"><?= h($layout->confFile($view)) ?></span> gespeichert und am Ende des
				<span class="mono">server</span>-Blocks eingebunden – zwischen den Markern <span class="mono"># &gt;&gt;&gt;&gt;</span> und
				<span class="mono"># &lt;&lt;&lt;&lt;</span>. Ein Fragment aus einer anderen Konfiguration lässt sich hier einsetzen.
				Abgelehnt wird, was aus diesem vHost herausführt: fremde Pfade, der Verzeichnisschutz, Bindung und Name des Hosts,
				Ziele auf diesem Rechner und Code im nginx-Prozess.</p>
			<form method="post">
				<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="conf"><input type="hidden" name="name" value="<?= h($view->name) ?>">
				<div class="editor">
					<div class="gutter" id="gutter" aria-hidden="true"></div>
					<textarea id="snippet" name="snippet" wrap="off" spellcheck="false" autocapitalize="off" autocorrect="off"
						data-error-line="<?= h($errorLine) ?>"><?= h($page->draft($view) ?? $page->snippet($view)) ?></textarea>
				</div>
				<div class="editor-foot">
					<button class="primary">Übernehmen</button>
					<span class="hint">Tabulator rückt ein. Leeres Feld entfernt die Datei. Schlägt <span class="mono">nginx -t</span> fehl,
						bleibt die bisherige Fassung aktiv und die Meldung erscheint oben.</span>
				</div>
			</form>
		</div>

		<div class="panel">
			<h2>Fertige Konfiguration</h2>
			<p class="hint">Was nginx für diesen Host tatsächlich liest – der erzeugte Block, der Verzeichnisschutz und
				Ihre eigenen Direktiven zusammengesetzt zu einem Text. Der hinterlegte Bereich ist Ihrer. Auf der
				Kommandozeile: <span class="mono">sudo vhost show <?= h($view->name) ?></span></p>
			<?php $effective = $page->effectiveConfig($view); ?>
			<?php if ($effective === ''): ?>
				<p class="hint">Noch keine Konfiguration geschrieben.</p>
			<?php else: ?>
				<pre class="listing"><?= confListing($effective) ?></pre>
			<?php endif ?>
		</div>

		<div class="panel">
			<h2>Docroot</h2>
			<p class="hint">Unterordner unterhalb von <span class="mono"><?= h($layout->webDir($view)) ?></span>, aus dem
				ausgeliefert wird. Leer bedeutet: <span class="mono">web/</span> selbst. Beim Ändern zieht der Inhalt mit,
				vorher wird der Basisordner gesichert. Der ACME-Pfad bleibt, wo er ist.</p>
			<form method="post" class="row" onsubmit="return confirm('Docroot-Unterordner ändern?\n\nDer Inhalt des bisherigen Docroots wird in den neuen verschoben. Vorher wird eine Sicherung angelegt.')">
				<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="subdir"><input type="hidden" name="name" value="<?= h($view->name) ?>">
				<input type="text" name="subdir" class="mono" value="<?= h($view->subdir ?? '') ?>" placeholder="z. B. src/www" autocomplete="off">
				<button class="primary">Übernehmen</button>
			</form>
			<p class="hint">Aktuell: <span class="mono"><?= h($layout->docroot($view)) ?></span></p>
		</div>

		<div class="panel">
			<h2>Pfade</h2>
			<dl class="facts">
				<dt>Basisordner</dt><dd><?= h($layout->baseDir($view)) ?></dd>
				<dt>Docroot</dt><dd><?= h($layout->docroot($view)) ?></dd>
				<dt>Privat</dt><dd><?= h($layout->privateDir($view)) ?></dd>
				<dt>Logs</dt><dd><?= h($layout->logsDir($view)) ?></dd>
				<?php if ($view->php): ?><dt>FPM-Socket</dt><dd><?= h($layout->phpSocket($view)) ?></dd><?php endif ?>
				<dt>Angelegt</dt><dd><?= h($view->createdAt) ?> UTC</dd>
			</dl>
		</div>

		<?php if (!$view->isPendingDeletion()): ?>
		<div class="panel">
			<h2>Entfernen</h2>
			<p class="hint">Der vHost wird sofort gesperrt und ist dann nicht mehr erreichbar. Endgültig entfernt wird er erst
				nach <?= h($config->removalGraceMinutes) ?> Minuten – bis dahin lässt sich das zurücknehmen. Die Dateien unter
				<span class="mono"><?= h($layout->baseDir($view)) ?></span> werden nie gelöscht.</p>
			<?= form('remove', ['name' => $view->name], 'vHost entfernen', 'danger', $view->name . " wirklich entfernen?\n\nDer vHost wird sofort gesperrt und ist dann nicht mehr erreichbar. Endgültig entfernt wird er erst in " . $config->removalGraceMinutes . ' Minuten - bis dahin können Sie das zurücknehmen.') ?>
		</div>
		<?php endif ?>
	</div>

	<div>
		<div class="panel">
			<h2>Verzeichnisschutz <?= badge($view->protect, 'aktiv', 'aus') ?></h2>
			<?php if ($view->protect): ?>
				<p class="hint">Zugriff nur mit freigegebener IP <em>oder</em> Benutzer und Passwort. Ohne Einträge ist der Docroot komplett gesperrt.</p>
				<?= form('protect', ['name' => $view->name, 'state' => 'off'], 'Schutz abschalten', 'danger', 'Verzeichnisschutz wirklich abschalten? Der Docroot ist dann frei erreichbar.') ?>
			<?php else: ?>
				<p class="hint">Der Docroot ist ohne Anmeldung erreichbar.</p>
				<?= form('protect', ['name' => $view->name, 'state' => 'on'], 'Schutz einschalten', 'primary') ?>
			<?php endif ?>

			<h2 style="margin-top:1.2rem">Benutzer</h2>
			<?php if ($users): ?>
				<table><?php foreach ($users as $u): ?>
					<tr><td class="mono"><?= h($u['username']) ?></td>
						<td style="text-align:right">
							<?= form('user_reset', ['name' => $view->name, 'username' => $u['username']], 'Passwort neu', 'small', 'Neues Passwort für ' . $u['username'] . ' erzeugen?' . "\n\n" . 'Das bisherige gilt danach nicht mehr. Das neue wird einmal angezeigt.') ?>
							<?= form('user_del', ['name' => $view->name, 'username' => $u['username']], 'entfernen', 'small danger', 'Benutzer ' . $u['username'] . ' entfernen?') ?>
						</td></tr>
				<?php endforeach ?></table>
			<?php else: ?><p class="hint">Keine Benutzer.</p><?php endif ?>
			<form method="post" style="margin-top:.6rem">
				<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="user_add"><input type="hidden" name="name" value="<?= h($view->name) ?>">
				<div class="row">
					<input type="text" name="username" placeholder="Benutzername" required autocomplete="off">
					<input type="text" name="password" class="mono pw" value="<?= h($page->suggestedPassword()) ?>" readonly
						aria-label="Erzeugtes Passwort" onclick="this.select()" title="Anklicken markiert das Passwort">
					<button class="primary">Anlegen</button>
				</div>
				<p class="hint">Das Passwort wird erzeugt, nicht eingegeben: 20 Zeichen aus Gross- und Kleinbuchstaben,
					Ziffern und <span class="mono">@=#+.,_-:;</span>. Es steht nirgends im Klartext – in der htpasswd-Datei
					liegt nur der Hash. Notieren Sie es, solange es angezeigt wird; sonst hilft nur „Passwort neu“.</p>
			</form>

			<h2 style="margin-top:1.2rem">Freigegebene IPs</h2>
			<?php if ($ips): ?>
				<table><?php foreach ($ips as $ip): ?>
					<tr><td class="mono"><?= h($ip) ?></td>
						<td style="text-align:right"><?= form('ip_del', ['name' => $view->name, 'cidr' => $ip], 'entfernen', 'small danger', 'IP ' . $ip . ' entfernen?') ?></td></tr>
				<?php endforeach ?></table>
			<?php else: ?><p class="hint">Keine IPs.</p><?php endif ?>
			<form method="post" class="row" style="margin-top:.6rem">
				<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="ip_add"><input type="hidden" name="name" value="<?= h($view->name) ?>">
				<input type="text" name="cidr" placeholder="203.0.113.5 oder 10.0.0.0/8" required>
				<button class="primary">Freigeben</button>
			</form>
		</div>

		<?php if ($view->supportsWwwRedirect()): $alias = (string)$view->aliasName(); ?>
		<div class="panel">
			<h2>Ausgeliefert unter <?= mark('ok', $view->canonicalName()) ?></h2>
			<p class="hint"><span class="mono"><?= h($alias) ?></span> wird mit 301 dorthin umgeleitet. Beide Namen zeigen
				auf denselben Docroot – ein Verzeichnis <span class="mono">www.<?= h($view->name) ?></span> gibt es nie.</p>
			<form method="post" class="row">
				<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="www"><input type="hidden" name="name" value="<?= h($view->name) ?>">
				<label><input type="radio" name="mode" value="bare" <?= $view->wwwMode !== 'www' ? 'checked' : '' ?>> <span class="mono"><?= h($view->name) ?></span></label>
				<label><input type="radio" name="mode" value="www" <?= $view->wwwMode === 'www' ? 'checked' : '' ?>> <span class="mono">www.<?= h($view->name) ?></span></label>
				<button class="primary">Übernehmen</button>
			</form>
			<?php if ($view->ssl && !$page->certificateCoversAlias($view)): ?>
				<p class="hint"><?= mark('bad', 'Zertifikat unvollständig') ?> Das Zertifikat deckt
					<span class="mono"><?= h($alias) ?></span> noch nicht ab. Wer diesen Namen über HTTPS aufruft, bekommt
					einen Zertifikatsfehler, bevor die Umleitung greift.</p>
				<?= form('cert_extend', ['name' => $view->name], 'Zertifikat um ' . $alias . ' erweitern', 'primary') ?>
				<p class="hint">Vor dem Antrag wird geprüft, ob beide Namen erreichbar sind – ein fehlender ließe den
					ganzen Antrag scheitern. Der Vorgang dauert einige Sekunden.</p>
			<?php endif ?>
		</div>
		<?php endif ?>

		<div class="panel">
			<h2>PHP <?= badge($view->php, 'aktiv', 'aus') ?></h2>
			<?php if ($view->php): ?>
				<p class="hint">Läuft in einem eigenen Pool als <span class="mono"><?= h($phpUser) ?></span>; Fehler landen in
					<span class="mono">logs/php.log</span>. Nur dieser Host kommt an seine Dateien.</p>
				<?= form('php', ['name' => $view->name, 'state' => 'off'], 'PHP abschalten', 'danger', 'PHP für ' . $view->name . ' abschalten? .php-Dateien werden danach mit 404 abgewiesen.') ?>
			<?php else: ?>
				<p class="hint">Schaltet einen eigenen php-fpm-Pool mit eigenem Systembenutzer frei. Ohne PHP werden
					<span class="mono">.php</span>-Dateien mit 404 abgewiesen, nie als Text ausgeliefert.</p>
				<?= form('php', ['name' => $view->name, 'state' => 'on'], 'PHP einschalten', 'primary') ?>
			<?php endif ?>
		</div>

		<?php if ($isDomain): ?>
		<div class="panel">
			<h2>Erreichbarkeit <?= reach($reach) ?></h2>
			<p><?= h($reach?->message ?? 'Nicht geprüft.') ?></p>
			<p class="hint">Geprüft wird bei jedem Aufruf dieser Seite derselbe Pfad, den Let's Encrypt für die Ausstellung
				abfragt. Der Test läuft von diesem Server aus – „erreichbar“ ist ein starkes Indiz, aber keine Garantie für
				jedes fremde Netz.</p>
		</div>

		<div class="panel">
			<h2>Let's Encrypt <?= badge($view->ssl, 'HTTPS aktiv', 'aus') ?></h2>
			<?php if ($view->ssl): ?>
				<p class="hint">HTTP wird auf HTTPS umgeleitet. Die Verlängerung übernimmt der certbot-Timer.</p>
				<?= form('ssl', ['name' => $view->name, 'state' => 'off'], 'HTTPS abschalten', 'danger', 'HTTPS für ' . $view->name . ' abschalten? Das Zertifikat bleibt gespeichert.') ?>
			<?php elseif (!$email): ?>
				<p class="hint">Zuerst oben eine Let's-Encrypt-E-Mail hinterlegen.</p>
				<button disabled>Zertifikat holen</button>
			<?php elseif ($reach === null || !$reach->isOk()): ?>
				<p class="hint">Solange die Domain nicht erreichbar ist, würde die Ausstellung scheitern – und Let's Encrypt
					zählt jeden Fehlversuch gegen das Kontingent der Domain (fünf pro Stunde). Zuerst DNS und Port 80 klären.</p>
				<button disabled>Zertifikat holen</button>
			<?php else: ?>
				<p class="hint">Die Erreichbarkeit ist geprüft. Der Vorgang dauert einige Sekunden.</p>
				<?= form('ssl', ['name' => $view->name, 'state' => 'on'], 'Zertifikat holen und HTTPS einschalten', 'primary') ?>
			<?php endif ?>
		</div>
		<?php endif ?>
	</div>
	</div>

<?php else: ?>
	<div class="panel">
		<h2>Alle vHosts</h2>
		<table>
			<tr><th>Name</th><th>Docroot</th><th>Schutz</th><th>PHP</th><th>HTTPS</th><th>Erreichbar</th></tr>
			<?php foreach ($vhosts as $v): ?>
			<tr>
				<td><a class="mono" href="/?v=<?= h(rawurlencode($v->name)) ?>"><?= h($v->name) ?></a>
					<?php if ($v->isLocal()): ?> <?= mark('local', 'lokal') ?><?php endif ?>
					<?php if ($v->isPendingDeletion()): ?> <?= mark('bad', 'wird entfernt', 'Endgültig entfernt am ' . $v->deletionDueAt($config->removalGraceMinutes)?->format('d.m.Y H:i') . ' UTC') ?><?php endif ?></td>
				<td class="mono"><?= h($layout->docroot($v)) ?></td>
				<td><?= badge($v->protect, 'aktiv', 'aus') ?></td>
				<td><?= badge($v->php, 'aktiv', 'aus') ?></td>
				<td><?= !$v->isLocal() ? badge($v->ssl, 'aktiv', 'aus') : '<span class="dash">–</span>' ?></td>
				<td><?= reach($reachable[$v->name] ?? null) ?></td>
			</tr>
			<?php endforeach ?>
			<tr>
				<td class="mono">localhost:<?= h($config->adminPort) ?> <?= mark('local', 'lokal') ?></td>
				<td class="mono"><?= h(__DIR__) ?></td>
				<td colspan="4" class="hint">diese Oberfläche</td>
			</tr>
		</table>
	</div>

	<div class="cols">
		<div class="panel">
			<h2>Neue Domain anlegen</h2>
			<form method="post" class="row">
				<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="create">
				<input type="text" name="domain" placeholder="example.com" required autocomplete="off" pattern="[A-Za-z0-9.-]+\.[A-Za-z]{2,}">
				<input type="text" name="subdir" placeholder="Unterordner (optional)" autocomplete="off">
				<button class="primary">Anlegen</button>
			</form>
			<p class="hint">Legt <span class="mono">/var/www/&lt;domain&gt;/web/</span> mit einer Startseite an. Der Docroot ist
				zunächst gesperrt: Verzeichnisschutz ohne Benutzer und ohne IP.</p>
		</div>
		<div class="panel">
			<h2>localhost-Hosts</h2>
			<p class="hint">Die werden auf der Kommandozeile angelegt, damit sie nicht versehentlich über die Oberfläche
				entstehen:</p>
			<p class="mono">sudo vhost add-local &lt;port&gt;</p>
			<p class="hint">Sie binden ausschliesslich an 127.0.0.1 und sind nie über das Internet erreichbar.</p>
		</div>
	</div>
<?php endif ?>
</main>
</div>

<script>
// Zeilennummern neben dem Textfeld. Bewusst ohne Fremdbibliothek: die Oberfläche
// soll keine Dateien aus dem Netz laden.
(function () {
	var ta = document.getElementById('snippet');
	var gutter = document.getElementById('gutter');
	if (!ta || !gutter) { return; }
	var errorLine = parseInt(ta.dataset.errorLine || '0', 10);

	function render() {
		var count = ta.value.split('\n').length;
		var html = '';
		for (var i = 1; i <= count; i++) {
			html += i === errorLine ? '<span class="bad">' + i + '</span>' : '<span>' + i + '</span>';
		}
		gutter.innerHTML = html;
		sync();
	}
	// Die Nummern scrollen mit dem Text mit; ohne Zeilenumbruch im Textfeld
	// (wrap="off") bleiben sie dabei Zeile für Zeile auf gleicher Höhe.
	function sync() { gutter.scrollTop = ta.scrollTop; }

	ta.addEventListener('input', render);
	ta.addEventListener('scroll', sync);

	// Tabulator rückt ein, statt den Fokus weiterzusetzen – in einer
	// Konfigurationsdatei ist Einrücken das Häufigere.
	ta.addEventListener('keydown', function (e) {
		if (e.key !== 'Tab' || e.ctrlKey || e.altKey || e.metaKey) { return; }
		e.preventDefault();
		var start = ta.selectionStart;
		var end = ta.selectionEnd;
		ta.setRangeText('\t', start, end, 'end');
		render();
	});

	render();
	if (errorLine > 0) {
		// Zur beanstandeten Zeile springen, statt sie suchen zu lassen.
		var lineHeight = parseFloat(getComputedStyle(ta).lineHeight) || 20;
		ta.scrollTop = Math.max(0, (errorLine - 3) * lineHeight);
		sync();
		ta.focus({ preventScroll: true });
	}
})();
</script>
</body>
</html>
