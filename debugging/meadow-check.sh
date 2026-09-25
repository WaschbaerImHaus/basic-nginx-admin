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

echo "== Bildschirmfotos"
shot geradeaus 1280x800 2
shot linkskurve 1280x800 7
shot rechtskurve 1280x800 22
shot handy 390x844 22
echo "ok   $OUT"
