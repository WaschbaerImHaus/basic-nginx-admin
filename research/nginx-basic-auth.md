Stand: 2026-09-17

# nginx Basic Auth: satisfy, allow/deny, auth_basic

## satisfy any/all

Die Direktive `satisfy` steuert, wie mehrere Zugriffsprüfungen kombiniert werden (Kontexte: `http`, `server`, `location`; Default: `all`).

- `satisfy all` (Standard): Zugriff nur, wenn **alle** beteiligten Module zustimmen (z. B. IP-Filter UND Basic Auth).
- `satisfy any`: Zugriff, wenn **mindestens eines** der Module zustimmt (z. B. IP-Filter ODER Basic Auth).

Beteiligte Module: `ngx_http_access_module` (allow/deny), `ngx_http_auth_basic_module`, `ngx_http_auth_request_module`, seit 1.13.10 `ngx_http_auth_jwt_module`.

Offizielles Beispiel für „IP oder Passwort reicht":

```nginx
location / {
    satisfy any;

    allow 192.168.1.0/24;
    deny  all;

    auth_basic           "closed site";
    auth_basic_user_file conf/htpasswd;
}
```

Quelle: nginx.org, `ngx_http_core_module#satisfy`.

## allow/deny (ngx_http_access_module)

- Regeln werden **sequenziell** ausgewertet, bis die **erste Übereinstimmung** greift – Reihenfolge ist relevant, nicht Spezifität.
- `allow`/`deny` werden aus der übergeordneten Ebene **vererbt**, aber nur, solange auf der aktuellen Ebene **keine einzige** `allow`- oder `deny`-Zeile definiert ist. Sobald eine Zeile im aktuellen Block steht, wird die gesamte Vererbung von oben verworfen – nicht nur ergänzt.
- Kontexte: `http`, `server`, `location`, `limit_except`.

Fallstrick: Wer in einem `location`-Block nur eine zusätzliche `allow`-Zeile ergänzen will, verliert dadurch alle geerbten `deny`-Regeln aus `server`, wenn er nicht auch `deny all;` erneut hinzufügt.

Quelle: nginx.org, `ngx_http_access_module`.

## auth_basic und Vererbung

- Syntax: `auth_basic string | off;` (Default: `off`). Kontexte: `http`, `server`, `location`, `limit_except`.
- Der String ist der angezeigte Realm und darf seit 1.3.10/1.2.7 Variablen enthalten.
- `auth_basic off;` ist der einzige Weg, die Vererbung aus einer höheren Ebene **gezielt aufzuheben** – z. B. um `/.well-known/acme-challenge/` von einer server-weiten Basic Auth auszunehmen:

```nginx
server {
    auth_basic           "vhost-admin";
    auth_basic_user_file /etc/nginx/htpasswd/example.com;

    location ^~ /.well-known/acme-challenge/ {
        auth_basic off;
        allow all;
    }
}
```

Das entspricht genau dem in diesem Projekt verwendeten Muster für den ACME-Challenge-Pfad.

Quelle: nginx.org, `ngx_http_auth_basic_module`.

## Unterstützte Hash-Formate in auth_basic_user_file

Das Dateiformat ist `name:password_hash[:comment]`. Laut nginx-Doku werden unterstützt:

| Format | Beschreibung | Quelle |
|---|---|---|
| `crypt()` | klassisches Unix-Crypt, delegiert an die System-`crypt()`/`crypt_r()`-Funktion (glibc/libxcrypt) | nginx.org auth_basic |
| Apache-MD5 (`$apr1$`) | 1000 iterierter, gesalzener MD5, nativ implementiert | nginx.org auth_basic |
| `{SHA}` | unsalted SHA-1, RFC 2307, seit 1.3.13 – **nicht empfehlenswert** (Rainbow-Table-anfällig) | nginx.org auth_basic |
| `{SSHA}` | gesalzenes SHA-1, RFC 2307, seit 1.3.13 | nginx.org auth_basic |

Wichtig: nginx implementiert `crypt`, `apr1`, `{SHA}` und `{SSHA}` selbst nativ – **alle anderen crypt()-Varianten** (darunter `$5$` SHA-256, `$6$` SHA-512, `$2y$` bcrypt) laufen über den `crypt`-Zweig und hängen vollständig davon ab, ob die System-Bibliothek (auf Ubuntu: libxcrypt) diese Formate unterstützt. Auf modernen Linux-Systemen mit libxcrypt funktionieren `$6$`-SHA-512-Hashes in der Praxis, weil libxcrypt sie in `crypt()` implementiert (Quelle: libxcrypt-Projekt auf GitHub; nginx-Trac-Ticket #1215 zur Diskussion von SHA-2-Unterstützung). Für bcrypt gibt es Berichte, dass es funktioniert, wenn `crypt_r()` des Systems `$2y$` unterstützt, aber auch Fehlermeldungen (`crypt_r() failed (22: Invalid argument)`) auf Systemen ohne diese Unterstützung – das ist **nicht einheitlich garantiert und plattformabhängig**, daher hier als „unbelegt für den Allgemeinfall" markiert (Quelle: nginx-Trac #382, diverse GitHub-Issues).

Fazit: SHA-512-Crypt (`$6$`) ist auf Ubuntu 26.04/nginx 1.28 mit libxcrypt die robusteste starke Option und deckt sich mit der aktuellen Projektwahl.

## Fallstricke

- **Reihenfolge von allow/deny**: erste passende Regel gewinnt, nicht die spezifischste.
- **`auth_basic off` nötig, nicht `auth_basic_user_file` weglassen**: Wird nur `auth_basic_user_file` entfernt, aber `auth_basic` bleibt geerbt aktiv, meldet nginx beim Start/Reload einen Konfigurationsfehler oder nutzt weiterhin die geerbte Datei.
- **Fehlende oder leere htpasswd-Datei**: `nginx -t` prüft nicht, ob die Datei existiert oder Einträge enthält – der Test kann erfolgreich sein, während zur Laufzeit alle Anfragen mit 403 (Datei nicht lesbar) oder dauerhaftem 401 (keine gültigen Nutzer, da Datei leer) scheitern. Das ist ein bekanntes, offenes nginx-Issue (Quelle: GitHub nginx/nginx#557).
- **CPU-Kosten starker Hashes**: nginx weist selbst darauf hin, dass starke crypt-Schemata (die für Login-Verifikation, nicht für Web-Traffic mit hoher Rate gedacht sind) bei jeder Anfrage neu berechnet werden – bei sehr vielen Requests relevant, für ein Admin-Panel mit wenigen Zugriffen unkritisch.

## Empfehlungen zu Dateirechten

Verbreitete Praxis (mehrere unabhängige Quellen, keine offizielle nginx-Norm):

- htpasswd-Datei außerhalb des Webroots ablegen (z. B. `/etc/nginx/htpasswd/<domain>`), niemals unter `/var/www/...`.
- Eigentümer `root:www-data` (oder die tatsächliche Worker-Gruppe), Rechte `640` – lesbar für den Worker-Prozess, nicht world-readable.
- Verzeichnis selbst mit `750` schützen.

## Ableitungen für vhost-admin

- BUG: Falls das `vhost`-CLI eine htpasswd-Datei jemals mit null verbleibenden Nutzern schreiben kann (z. B. letzter Benutzer gelöscht), sperrt das den vHost komplett aus, ohne dass `nginx -t` das erkennt – sollte serverseitig verhindert werden (mindestens ein Eintrag erzwingen oder vHost explizit deaktivieren statt leere Datei zu erzeugen).
- FEATURE: Ein eigener Check in `vhost add-local`/`vhost reload` könnte vor jedem Reload prüfen, ob jede referenzierte `auth_basic_user_file` existiert und nicht leer ist, statt sich auf `nginx -t` zu verlassen, das genau das nicht prüft.
- OPTIMIZE: Sicherstellen, dass generierte Configs beim Hinzufügen von `allow`-Zeilen für neue IPs immer auch `deny all;` am Ende neu schreiben, statt nur eine Zeile einzufügen – sonst geht durch die Vererbungsregel (ganz-oder-gar-nicht) versehentlich der Schutz verloren.
- OPTIMIZE: Da `$6$`-Hashes über den System-`crypt()` laufen und nicht nativ in nginx implementiert sind, lohnt sich ein einmaliger Funktionstest bei Erstinstallation (z. B. Testlogin nach `vhost add-local`), um stillschweigende libxcrypt-Inkompatibilitäten früh zu erkennen.
- FEATURE: Dateirechte der htpasswd-Dateien (Owner/Modus) könnten vom `vhost`-CLI beim Erzeugen aktiv gesetzt und bei jedem `vhost` Aufruf verifiziert werden, statt sich auf einmalige Installationsschritte zu verlassen.

## Quellen

- https://nginx.org/en/docs/http/ngx_http_auth_basic_module.html
- https://nginx.org/en/docs/http/ngx_http_core_module.html#satisfy
- https://nginx.org/en/docs/http/ngx_http_access_module.html
- https://docs.nginx.com/nginx/admin-guide/security-controls/configuring-http-basic-authentication/
- https://github.com/nginx/nginx/issues/557
- https://trac.nginx.org/nginx/ticket/1215
- https://trac.nginx.org/nginx/ticket/382
- https://trac.nginx.org/nginx/ticket/50
- https://github.com/solardiz/libxcrypt
