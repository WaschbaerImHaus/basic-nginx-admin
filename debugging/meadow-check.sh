#!/usr/bin/env bash
# Prueft die Blumenwiese auf der Honigtopf-Seite mit einem kopflosen Chrome:
# Bildschirmfotos zu mehreren Flugzeitpunkten, Flugzustand, "Bewegung reduzieren".
#
# Braucht chrome-headless-shell (Chrome for Testing), standardmaessig unter
# ~/tools/chrome-headless-shell-linux64/. Einrichten:
#   V=$(curl -s https://googlechromelabs.github.io/chrome-for-testing/last-known-good-versions.json \
#       | python3 -c 'import sys,json;print(json.load(sys.stdin)["channels"]["Stable"]["version"])')
#   curl -Lo chs.zip "https://storage.googleapis.com/chrome-for-testing-public/$V/linux64/chrome-headless-shell-linux64.zip"
#
# Aufruf: ./debugging/meadow-check.sh [ausgabeverzeichnis]
#
# Der Parameter ?t=Sekunden spult den Flug vor: Ohne Grafikkarte liefert Chrome zu
# wenige Bilder, als dass die Animation hier von selbst vorankaeme.
set -euo pipefail

CHROME="${CHROME:-$HOME/tools/chrome-headless-shell-linux64/chrome-headless-shell}"
OUT="${1:-$(mktemp -d)}"
SITE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../honeypot/site" && pwd)"
PORT=8765
[ -x "$CHROME" ] || { echo "chrome-headless-shell fehlt: $CHROME" >&2; exit 1; }
mkdir -p "$OUT"

python3 -m http.server "$PORT" --bind 127.0.0.1 --directory "$SITE" >/dev/null 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null' EXIT
sleep 1

shot() { # shot <name> <breite>x<hoehe> <sekunden> [zusatzflags]
	timeout 90 "$CHROME" --no-sandbox --hide-scrollbars --window-size="${2/x/,}" \
		--virtual-time-budget=1500 ${4:-} --screenshot="$OUT/$1.png" \
		"http://127.0.0.1:$PORT/?t=$3" 2>/dev/null
}
state() { # state <sekunden> [zusatzflags] -> Flugzustand aus dem DOM
	timeout 60 "$CHROME" --no-sandbox --virtual-time-budget=1500 ${2:-} --dump-dom \
		"http://127.0.0.1:$PORT/?t=$1" 2>/dev/null \
		| grep -oE -- '--cam-a: ?[-0-9.]+|--bank: ?[-0-9.]+' | tail -2 | tr '\n' ' '
}

echo "== Flugzustand (Winkel, Schraeglage)"
for t in 2 5 8 11 14 17 20 24; do printf '  t=%-3s %s\n' "$t" "$(state "$t")"; done

echo "== Pflanzen im Dokument"
COUNT=$(timeout 60 "$CHROME" --no-sandbox --virtual-time-budget=1500 --dump-dom \
	"http://127.0.0.1:$PORT/?t=1" 2>/dev/null | grep -o 'class="f p-' | wc -l)
[ "$COUNT" -gt 100 ] && echo "ok   $COUNT Pflanzen" || { echo "FEHLER: nur $COUNT Pflanzen" >&2; exit 1; }

echo "== Bewegung reduzieren"
# Ohne ?t: Die Animation laeuft von selbst; mit reduzierter Bewegung muss sie stehen.
Z=$(timeout 60 "$CHROME" --no-sandbox --force-prefers-reduced-motion --virtual-time-budget=3000 \
	--dump-dom "http://127.0.0.1:$PORT/" 2>/dev/null | grep -oE -- '--cam-z: ?[-0-9.]+' | head -1)
[ "$Z" = "--cam-z: 0.00" ] && echo "ok   Kamera steht ($Z)" || { echo "FEHLER: Kamera bewegt sich ($Z)" >&2; exit 1; }

echo "== Fliegt sie von selbst?"
# Der kopflose Chrome liefert ohne sichtbare Flaeche keine Animationsbilder. Eine Kopie
# ersetzt requestAnimationFrame deshalb durch einen 16-ms-Takt; unter virtueller Zeit
# laufen die Timer dann deterministisch. So wird die echte Schleife geprueft, nicht der
# Vorlauf ?t= (gemeldet am 2026-09-25: "die Biene bewegt sich nicht").
LIVE="$(mktemp -d)"
python3 - "$SITE/index.html" "$LIVE/index.html" <<'PY2'
import sys
s = open(sys.argv[1], encoding='utf-8').read()
def shim(ms):
    return ("<script>window.requestAnimationFrame = function (cb) {"
            " return setTimeout(function () { cb(performance.now()); }, %d); };</script>\n" % ms)
open(sys.argv[2], 'w', encoding='utf-8').write(s.replace('<script>', shim(16) + '<script>', 1))
# Zweite Kopie mit 60 ms je Bild (rund 16 Bilder/s): ein zu langsamer Rechner.
open(sys.argv[2].replace('index.html', 'slow.html'), 'w', encoding='utf-8').write(s.replace('<script>', shim(60) + '<script>', 1))
PY2
python3 -m http.server $((PORT + 1)) --bind 127.0.0.1 --directory "$LIVE" >/dev/null 2>&1 &
LIVESERVER=$!
trap 'kill $SERVER $LIVESERVER 2>/dev/null; rm -rf "$LIVE"' EXIT
sleep 1
flight() { timeout 90 "$CHROME" --no-sandbox --window-size=1280,800 --virtual-time-budget="$1" --dump-dom \
	"http://127.0.0.1:$((PORT + 1))/" 2>/dev/null | grep -oE -- "--$2: ?[-0-9.]+" | head -1 | grep -oE -- '-?[0-9.]+$'; }
Z1=$(flight 1000 cam-z); Z3=$(flight 3000 cam-z)
X1=$(flight 1000 bee-x); X3=$(flight 3000 bee-x)
python3 -c "import sys; z1,z3=float('$Z1'),float('$Z3'); sys.exit(0 if z3 < z1 - 200 else 1)" \
	&& echo "ok   die Wiese zieht vorbei (z: $Z1 -> $Z3)" || { echo "FEHLER: Kamera steht (z: $Z1 -> $Z3)" >&2; exit 1; }
[ "$X1" != "$X3" ] && echo "ok   die Biene schwirrt (x: $X1 -> $X3)" || { echo "FEHLER: Biene steht (x: $X1)" >&2; exit 1; }

echo "== Qualität nach Bildrate"
# Die Seite zählt intern ihre Bilder je Sekunde (data-fps) und senkt bei Ruckeln die
# Qualität (data-quality, Klasse lite). Flüssig muss alles bleiben, wie es ist.
attrs() { timeout 90 "$CHROME" --no-sandbox --window-size=1280,800 --virtual-time-budget=12000 --dump-dom \
	"http://127.0.0.1:$((PORT + 1))/$1" 2>/dev/null | grep -oE '<div class="meadow[^>]*>' | head -1; }
FAST=$(attrs index.html); SLOW=$(attrs slow.html)
grep -q 'data-fps="6[0-9]"\|data-fps="5[6-9]"' <<<"$FAST" && ! grep -q 'data-quality' <<<"$FAST" \
	&& echo "ok   flüssig: volle Qualität ($FAST)" || { echo "FEHLER: flüssig, aber $FAST" >&2; exit 1; }
grep -q 'lite' <<<"$SLOW" && grep -q 'data-quality="[^v]' <<<"$SLOW" \
	&& echo "ok   ruckelnd: gesenkt ($SLOW)" || { echo "FEHLER: ruckelnd, aber $SLOW" >&2; exit 1; }

echo "== Bildschirmfotos"
shot geradeaus 1280x800 2
shot linkskurve 1280x800 7
shot rechtskurve 1280x800 22
shot handy 390x844 22
# Sparsamste Stufe: ohne Unschärfe, gut ein Drittel der Blumen.
timeout 90 "$CHROME" --no-sandbox --hide-scrollbars --window-size=1280,800 --virtual-time-budget=1500 \
	--screenshot="$OUT/sparsam.png" "http://127.0.0.1:$PORT/?t=7&quality=4" 2>/dev/null
echo "ok   $OUT"
