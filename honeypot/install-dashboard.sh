#!/usr/bin/env bash
# Richtet die Honigtopf-Ansicht auf einem vHost ein.
#
# Die Ansicht laeuft im php-fpm-Pool dieses vHosts und kommt damit weder an die Logs
# (die gehoeren root) noch an /opt/vhost-admin (open_basedir). Deshalb bekommt sie
# beides als Kopie im eigenen Bereich: die Auswertungsklassen unter
# private/honeypot-lib/, die Tagesberichte unter private/honeypot/.
#
# Aufruf: sudo ./honeypot/install-dashboard.sh <ansichtshost> <honigtopf-host> [weitere ...]
set -euo pipefail

VIEW="${1:?Aufruf: sudo ./honeypot/install-dashboard.sh <ansichtshost> <honigtopf-host> [weitere ...]}"
shift
HOSTS=("$@")
[ "${#HOSTS[@]}" -gt 0 ] || { echo "Mindestens ein Honigtopf-Host noetig." >&2; exit 1; }

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# Im Projektverzeichnis liegen Klassen und Ansicht unter src/, in der Installation
# (/opt/vhost-admin) flach daneben.
if [ -d "$ROOT/src/lib/Honeypot" ]; then LIB="$ROOT/src/lib/Honeypot"; else LIB="$ROOT/lib/Honeypot"; fi
if [ -d "$ROOT/src/public/honeypot" ]; then PAGE="$ROOT/src/public/honeypot"; else PAGE="$ROOT/public/honeypot"; fi
[ -d "$LIB" ] || { echo "Auswertungsklassen nicht gefunden: $LIB" >&2; exit 1; }
[ -d "$PAGE" ] || { echo "Ansichtsdateien nicht gefunden: $PAGE" >&2; exit 1; }
# Genau die "root"-Zeile auf Server-Ebene (vier Leerzeichen); die im ACME-Block ist
# tiefer eingerueckt und zeigt auf web/, nicht auf den Docroot.
DOCROOT="$(vhost show "$VIEW" | grep -m1 -oP '^    root \K[^;]+')"
[ -d "$DOCROOT" ] || { echo "Docroot nicht gefunden: $DOCROOT" >&2; exit 1; }

# PHP muss laufen, sonst weist nginx die .php-Dateien mit 404 ab (als Text werden sie
# nie ausgeliefert).
if ! vhost show "$VIEW" | grep -q 'fastcgi_pass'; then
	echo "PHP ist fuer $VIEW nicht eingeschaltet: sudo vhost php $VIEW on" >&2
	exit 1
fi

# Vom Docroot aufwaerts bis web/, darueber liegt der Basisordner mit private/.
# Der Docroot kann web/ selbst oder ein Unterordner davon sein.
BASE="$DOCROOT"
while [ "$(basename "$BASE")" != "web" ] && [ "$BASE" != "/" ]; do BASE="$(dirname "$BASE")"; done
BASE="$(dirname "$BASE")"
[ -d "$BASE/private" ] || { echo "Kein private/ unter $BASE" >&2; exit 1; }

OWNER="$(stat -c '%U' "$DOCROOT")"
GROUP="$(stat -c '%G' "$DOCROOT")"
POWNER="$(stat -c '%U' "$BASE/private")"

# 1. Ansicht in den Docroot
for file in index.php detail.php bootstrap.php style.css; do
	install -m 644 -o "$OWNER" -g "$GROUP" "$PAGE/$file" "$DOCROOT/$file"
done

# nginx liefert index.html vor index.php aus. Eine Platzhalterseite aus dem Anlegen des
# vHosts wuerde die Ansicht also verdecken. Sie wird beiseitegelegt, nicht geloescht.
if [ -f "$DOCROOT/index.html" ] && grep -q 'HTTP 200' "$DOCROOT/index.html"; then
	mv "$DOCROOT/index.html" "$DOCROOT/index.html.platzhalter"
	echo "Platzhalterseite beiseitegelegt: $DOCROOT/index.html.platzhalter"
fi

# 2. Auswertungsklassen in den privaten Bereich (nicht ausgeliefert, aber in open_basedir)
install -d -m 750 -o "$POWNER" "$BASE/private/honeypot-lib/Honeypot"
install -m 640 -o "$POWNER" "$LIB"/*.php "$BASE/private/honeypot-lib/Honeypot/"
install -d -m 750 -o "$POWNER" "$BASE/private/honeypot"

# 3. Dienst auf diese Hosts einstellen
cat > /etc/default/vhost-admin-honeypot <<EOF
# Hosts, deren Logs taeglich ausgewertet werden, und der vHost, der die Ansicht zeigt.
# Geschrieben von honeypot/install-dashboard.sh.
HONEYPOT_HOSTS="${HOSTS[*]}"
HONEYPOT_DASHBOARD="$VIEW"
EOF
chmod 644 /etc/default/vhost-admin-honeypot

# 4. Rechte nachziehen (private/ wird mit PHP fuer den Pool lesbar) und einmal auswerten
vhost fix-permissions "$VIEW" >/dev/null
chgrp -R "$(stat -c '%G' "$BASE/private")" "$BASE/private/honeypot-lib" "$BASE/private/honeypot"
php "$ROOT/honeypot/analyse.php" "--dashboard=$VIEW" "${HOSTS[@]}"
chown -R "$POWNER:$(stat -c '%G' "$BASE/private")" "$BASE/private/honeypot"
find "$BASE/private/honeypot" -type f -exec chmod 640 {} +
find "$BASE/private/honeypot" -type d -exec chmod 750 {} +

echo "Ansicht liegt in $DOCROOT, Daten in $BASE/private/honeypot."
