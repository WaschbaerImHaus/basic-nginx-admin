#!/usr/bin/env bash
# Legt die statischen Honigtopf-Dateien in den Docroot eines vHosts.
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

echo "Honigtopf-Dateien in $DOCROOT abgelegt."
