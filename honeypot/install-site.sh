#!/usr/bin/env bash
# Legt die statischen Honigtopf-Dateien in den Docroot eines vHosts und richtet die
# Koeder ein (phpinfo, .env - siehe src/lib/Honeypot/Decoys.php).
# Aufruf: sudo ./honeypot/install-site.sh <vhost-name>
set -euo pipefail

NAME="${1:?Aufruf: sudo ./honeypot/install-site.sh <vhost-name>}"
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/site" && pwd)"
# Genau die "root"-Zeile auf Server-Ebene (vier Leerzeichen). Die im ACME-Block ist
# tiefer eingerueckt und zeigt auf web/, nicht auf den Docroot.
DOCROOT="$(vhost show "$NAME" | grep -m1 -oP '^    root \K[^;]+')"
[ -d "$DOCROOT" ] || { echo "Docroot nicht gefunden: $DOCROOT" >&2; exit 1; }

OWNER="$(stat -c '%U' "$DOCROOT")"
GROUP="$(stat -c '%G' "$DOCROOT")"

install -m 644 -o "$OWNER" -g "$GROUP" "$SRC/index.html" "$DOCROOT/index.html"
install -m 644 -o "$OWNER" -g "$GROUP" "$SRC/robots.txt" "$DOCROOT/robots.txt"
install -m 644 -o "$OWNER" -g "$GROUP" "$SRC/favicon.svg" "$DOCROOT/favicon.svg"
install -m 644 -o "$OWNER" -g "$GROUP" "$SRC/favicon.ico" "$DOCROOT/favicon.ico"
install -d -m 755 -o "$OWNER" -g "$GROUP" "$DOCROOT/admin"
install -m 644 -o "$OWNER" -g "$GROUP" "$SRC/admin/index.html" "$DOCROOT/admin/index.html"

# Koeder: Dateien nach /.koeder/ (von aussen nicht abrufbar, nginx liefert sie nur
# ueber die Umleitung aus), Logformat und Pfadzuordnung auf http-Ebene, Umleitung und
# Kennung im Server-Block ueber die eigenen Direktiven. Eigene Direktiven des Nutzers
# bleiben erhalten; ersetzt wird nur der markierte Koederabschnitt.
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LIB="$ROOT/src/lib"; [ -d "$LIB/Honeypot" ] || LIB="$ROOT/lib"
LOGS="$(vhost show "$NAME" | grep -m1 -oP '^    access_log \K\S+(?=/access\.log;)')"
[ -n "$LOGS" ] || { echo "Logordner nicht gefunden" >&2; exit 1; }
install -d -m 755 -o "$OWNER" -g "$GROUP" "$DOCROOT/.koeder"
install -m 644 -o "$OWNER" -g "$GROUP" "$SRC"/koeder/* "$DOCROOT/.koeder/"
decoys() { # decoys <methode> [argument] - ruft Honeypot\Decoys auf
	php -r 'require $argv[1] . "/Honeypot/Decoys.php"; $m = $argv[2]; echo $m === "merge"
		? Honeypot\Decoys::merge(stream_get_contents(STDIN), Honeypot\Decoys::serverSnippet($argv[3]))
		: Honeypot\Decoys::$m();' "$LIB" "$@"
}
decoys httpConfig > /etc/nginx/conf.d/vhost-admin-decoy.conf
nginx -t -q || { rm -f /etc/nginx/conf.d/vhost-admin-decoy.conf; echo "nginx lehnt die Koederkonfiguration ab" >&2; exit 1; }
vhost conf-show "$NAME" | decoys merge "$LOGS" | vhost conf "$NAME"

echo "Honigtopf-Dateien und Koeder in $DOCROOT abgelegt."
