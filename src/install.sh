#!/usr/bin/env bash
# Installiert vhost-admin (nginx + PHP/SQLite-Verwaltung) auf Ubuntu/Debian.
#
#   sudo ./install.sh [--owner BENUTZER]
#
# --owner: Besitzer der Ordner unter /var/www (Standard: der aufrufende sudo-Benutzer).
# Mehrfach ausführbar (Update). Für einen Umzug zusätzlich /var/lib/vhost-admin,
# /var/www und /etc/letsencrypt mitnehmen – die nginx-Configs erzeugt das Script neu.
# Autor: Kurt Ingwer
# Letzte Änderung: 2026-10-18 00:40
set -euo pipefail

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP=/opt/vhost-admin
OWNER="${SUDO_USER:-root}"

while [ $# -gt 0 ]; do
	case "$1" in
		--owner) OWNER="$2"; shift 2 ;;
		*) echo "Unbekannte Option: $1" >&2; exit 2 ;;
	esac
done

[ "$(id -u)" -eq 0 ] || { echo "Bitte als root bzw. mit sudo ausführen." >&2; exit 1; }
id "$OWNER" >/dev/null 2>&1 || { echo "Benutzer '$OWNER' existiert nicht." >&2; exit 1; }

# Reihenfolge erzwingen: erst das Projekt honeypot VOLLSTÄNDIG installieren, dann vhost-admin.
# Abbruch in zwei Fällen:
#  1. Der alte Timer ist noch aktiv. Er startet die Auswertung unter /opt/vhost-admin/honeypot,
#     die die Honigtopf-Klassen aus $APP/lib braucht. Die ersetzt dieser Installer weiter unten
#     durch eine Fassung ohne Honigtopf – die Auswertung bräche danach stündlich lautlos ab.
#  2. /etc/default/honeypot existiert, honeypot-analyse.timer ist aber nicht aktiv. Der
#     honeypot-Installer legt die Datei an, BEVOR er die Tagesnotizen kopiert und seine Timer
#     einschaltet; die alten Timer sind dann schon aus. Brach er dazwischen ab, ist die
#     Übernahme unvollständig – erst der aktive neue Timer zeigt, dass er durchgelaufen ist.
# VHOST_ADMIN_SKIP_HONEYPOT_CHECK=1 übergeht die Prüfung, wenn das bewusst so gewollt ist. Die
# Variable muss hinter sudo stehen (sudo VAR=1 ./install.sh): sudo setzt die Umgebung zurück.
if [ "${VHOST_ADMIN_SKIP_HONEYPOT_CHECK:-}" != 1 ] && {
	systemctl is-enabled --quiet vhost-admin-honeypot.timer 2>/dev/null ||
	{ [ -f /etc/default/honeypot ] && ! systemctl is-enabled --quiet honeypot-analyse.timer 2>/dev/null; }
}; then
	echo "Die Übernahme durch das Projekt honeypot ist nicht abgeschlossen (alter Timer vhost-admin-honeypot.timer" >&2
	echo "noch aktiv oder honeypot-analyse.timer nicht aktiv): erst honeypot vollständig installieren" >&2
	echo "(im Projekt honeypot: sudo ./src/install.sh), dann vhost-admin." >&2
	echo "Bewusst übergehen: sudo VHOST_ADMIN_SKIP_HONEYPOT_CHECK=1 ./install.sh" >&2
	exit 1
fi

echo "== Pakete installieren"
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get install -y -q nginx php-fpm php-cli php-sqlite3 certbot phpunit

# Die eigenen Direktiven und der PHP-Block verweisen auf diese Datei; ohne sie
# scheitert jeder nginx-Test mit "open() ... failed".
[ -f /etc/nginx/fastcgi_params ] || { echo "/etc/nginx/fastcgi_params fehlt - ist nginx vollstaendig installiert?" >&2; exit 1; }

echo "== Anwendung nach $APP (Besitzer von /var/www/*: $OWNER)"
install -d -m 755 "$APP" "$APP/bin" "$APP/templates"
rm -rf "$APP/lib" "$APP/public"
cp -r "$SRC/lib" "$APP/lib"
find "$APP/lib" -type d -exec chmod 755 {} + -o -type f -exec chmod 644 {} +
install -m 644 "$SRC/bootstrap.php" "$APP/bootstrap.php"
install -m 644 "$SRC"/templates/index.html "$APP/templates/"
install -m 755 "$SRC"/bin/vhost.php "$APP/bin/"
install -m 755 "$SRC"/bin/vhost /usr/local/sbin/vhost
sed -i "s|'wwwOwner' => '[^']*'|'wwwOwner' => '$OWNER'|" "$APP/lib/VhostAdmin/Config.php"
grep -q "'wwwOwner' => '$OWNER'" "$APP/lib/VhostAdmin/Config.php" || { echo "Besitzer konnte nicht gesetzt werden" >&2; exit 1; }

echo "== Oberfläche nach /var/www/localhost-8080/web"
# Basisordner nicht gruppenbeschreibbar (C2, Abschlussreview 2026-09-20): www-data
# könnte sonst Einträge darin ersetzen, auch den root-eigenen conf/-Ordner. Nur web/
# selbst bleibt für www-data beschreibbar (analog zu VhostLayout::directories()).
install -d -m 755 -o "$OWNER" -g "$OWNER" /var/www/localhost-8080
install -d -m 2775 -o "$OWNER" -g www-data /var/www/localhost-8080/web
install -d -m 750 -o root -g www-data /var/www/localhost-8080/conf
install -d -m 750 -o root -g "$OWNER" /var/www/localhost-8080/cert
# logs/ durchquerbar (0751): Nach der Rotation oeffnen die nginx-Worker (www-data) die
# Dateien selbst neu. Ohne Durchgang schreiben sie in die umbenannte Datei weiter, die
# logrotate am Folgetag loescht (siehe BUGS.md, 2026-09-25).
install -d -m 751 -o root -g "$OWNER" /var/www/localhost-8080/logs
install -d -m 750 -o "$OWNER" -g "$OWNER" /var/www/localhost-8080/private
# Aus einer früheren Fassung liegt index.php eventuell noch flach im Basisordner.
if [ -f /var/www/localhost-8080/index.php ]; then
	mv /var/www/localhost-8080/index.php /var/www/localhost-8080/web/index.php
fi
install -m 644 -o "$OWNER" -g www-data "$SRC"/public/*.php /var/www/localhost-8080/web/

echo "== sudo-Regel für www-data"
install -m 440 "$SRC/etc/sudoers-vhost-admin" /etc/sudoers.d/vhost-admin
visudo -cf /etc/sudoers.d/vhost-admin >/dev/null

echo "== nginx"
install -d -m 750 -g www-data /etc/nginx/auth
install -m 644 "$SRC/etc/nginx-admin.conf"   /etc/nginx/sites-available/vhost-admin.conf
install -m 644 "$SRC/etc/nginx-default.conf" /etc/nginx/sites-available/00-default.conf
# Inhalt prüfen, nicht die Größe: Dateien unter /proc melden immer 0 Byte ("-s" war stets falsch).
if ! grep -q . /proc/net/if_inet6 2>/dev/null; then
	sed -i '/listen \[::/d' /etc/nginx/sites-available/vhost-admin.conf /etc/nginx/sites-available/00-default.conf
fi
ln -sfn /etc/nginx/sites-available/vhost-admin.conf /etc/nginx/sites-enabled/vhost-admin.conf
ln -sfn /etc/nginx/sites-available/00-default.conf  /etc/nginx/sites-enabled/00-default.conf
rm -f /etc/nginx/sites-enabled/default

echo "== certbot: nginx nach Zertifikatsverlängerung neu laden"
install -d /etc/letsencrypt/renewal-hooks/deploy
install -m 755 "$SRC/etc/certbot-deploy-nginx.sh" /etc/letsencrypt/renewal-hooks/deploy/nginx-reload.sh

echo "== logrotate"
sed "s/create 0640 root root/create 0640 root $OWNER/" "$SRC/etc/logrotate-vhost-admin" > /etc/logrotate.d/vhost-admin
chmod 644 /etc/logrotate.d/vhost-admin
logrotate -d /etc/logrotate.d/vhost-admin >/dev/null 2>&1 || { echo "logrotate-Konfiguration ist fehlerhaft" >&2; exit 1; }

echo "== Dienste"
for unit in /usr/lib/systemd/system/php*-fpm.service; do
	systemctl enable --now "$(basename "$unit")" >/dev/null
done
systemctl enable --now nginx >/dev/null

echo "== Datenbank und nginx-Configs"
# Verzeichnis und Datei gehören root; www-data (die Oberfläche) bekommt per Gruppe nur
# Leserechte (0750/0640) – geschrieben wird ausschließlich über "vhost" als root. Die
# endgültigen Rechte setzt VhostService::fixDatabasePermissions(), das jeder CLI-Lauf
# aufruft; hier nur, damit "vhost init" gleich in ein passendes Verzeichnis schreibt.
install -d -m 750 -o root -g www-data /var/lib/vhost-admin
/usr/local/sbin/vhost init
# Zeitgeber, der abgelaufene Loeschvormerkungen endgueltig entfernt. Ohne ihn bliebe
# ein entfernter vHost unbegrenzt gesperrt liegen, statt nach der Frist zu verschwinden.
install -m 644 "$SRC/etc/vhost-admin-purge.service" /etc/systemd/system/vhost-admin-purge.service
install -m 644 "$SRC/etc/vhost-admin-purge.timer" /etc/systemd/system/vhost-admin-purge.timer
systemctl daemon-reload
systemctl enable --now vhost-admin-purge.timer >/dev/null

# Wartet, bis ein Dienst nicht mehr läuft – höchstens 120 Sekunden, dann Hinweis und weiter.
# Abgefragt wird ActiveState, nicht "systemctl is-active": Ein Type=oneshot-Dienst steht
# während seines Laufs auf "activating", und dafür meldet is-active "nicht aktiv".
wait_until_finished() {
	local unit="$1" waited=0
	while [ "$waited" -lt 120 ]; do
		case "$(systemctl show -p ActiveState --value "$unit" 2>/dev/null || true)" in
			active|activating|deactivating|reloading) sleep 1; waited=$((waited + 1)) ;;
			*) return 0 ;;
		esac
	done
	echo "Hinweis: $unit läuft nach 120 s noch und wird jetzt abgebrochen." >&2
}

# Der Honigtopf ist seit 2026-10-15 ein eigenes Projekt (/opt/honeypot). Seine alten
# Reste hier werden erst abgeräumt, wenn er VOLLSTÄNDIG übernommen hat: /etc/default/honeypot
# existiert UND honeypot-analyse.timer ist aktiv. Die Datei allein reicht nicht – sie entsteht
# vor dem Kopieren der Tagesnotizen (siehe Abbruchprüfung oben). Daten unter /var/www bleiben
# unberührt.
if [ -f /etc/default/honeypot ] && systemctl is-enabled --quiet honeypot-analyse.timer 2>/dev/null; then
	for old in vhost-admin-honeypot vhost-admin-networks; do
		# Erst den Timer aus, damit kein neuer Lauf startet. Ein gerade laufender Dienst endet
		# damit nicht; stop bräche ihn (Type=oneshot) per SIGTERM mitten im Schreiben ab.
		# Deshalb warten, bis er fertig ist – stop greift nur noch nach Ablauf der Obergrenze.
		systemctl disable --now "$old.timer" >/dev/null 2>&1 || true
		wait_until_finished "$old.service"
		systemctl stop "$old.service" >/dev/null 2>&1 || true
		rm -f "/etc/systemd/system/$old.timer" "/etc/systemd/system/$old.service"
	done
	rm -rf "$APP/honeypot" "$APP/public/honeypot" "$APP/research/honeypot"
	rm -f /etc/default/vhost-admin-honeypot
	systemctl daemon-reload
fi

/usr/local/sbin/vhost migrate-layout
# Rechte bestehender vHosts auf den aktuellen Stand bringen. Nötig, weil sich die
# Soll-Rechte zwischen Fassungen ändern koennen (z.B. conf/ am 2026-09-20 von
# root:www-data auf root:<besitzer>, damit der Besitzer lesen darf, aber nur root
# schreibt). Ohne diesen Schritt bliebe ein Bestandshost auf den alten Rechten.
/usr/local/sbin/vhost fix-permissions
/usr/local/sbin/vhost render

echo
echo "Fertig."
echo "  Oberfläche : http://127.0.0.1:8080  (nur lokal; von außen z.B. per ssh -L 8080:127.0.0.1:8080 <host>)"
echo "  CLI        : sudo vhost --help"
