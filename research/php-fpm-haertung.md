Stand: 2026-09-17

# PHP-FPM-Härtung für eine lokale Verwaltungsoberfläche

Kontext: Die PHP-Oberfläche läuft nur auf `127.0.0.1:8080` als `www-data`, delegiert schreibende Aktionen an ein root-CLI (`vhost`) per sudoers. Härtung muss also zwei Dinge gleichzeitig leisten: die PHP-Ebene absichern **und** den Sprung von `www-data` zu `root` über sudo eng führen.

## open_basedir

`open_basedir` beschränkt Datei-/IO-Funktionen (`fopen`, `include`, `require`, …) auf eine Liste von Verzeichnissen; Pfade außerhalb schlagen mit Warnung fehl. Mehrere Pfade werden unter Linux mit `:` getrennt.

```ini
open_basedir = /var/www/vhost-admin:/tmp
```

Wichtige Einschränkung (mehrfach unabhängig belegt): Erweiterungen, die die PHP-Stream-Schicht umgehen (bestimmte PDO-Treiber, einzelne PECL-Extensions) sowie Unix-Domain-Sockets werden von `open_basedir` **nicht** erfasst – es ist eine zusätzliche Hürde, kein vollständiger Sandbox-Mechanismus.

Quelle: php.net/manual/en/ini.core.php; bencteux.fr „PHP's open_basedir is not a security feature" (Einschränkungen, ergänzende Quelle).

## disable_functions

Klassische Empfehlung ist, Shell-Ausführungsfunktionen zu deaktivieren: `exec, shell_exec, system, passthru, popen, pcntl_exec, pcntl_fork` u. Ä.

**Kollidiert mit diesem Projekt**: `proc_open` (und ggf. `exec`/`shell_exec`, je nach Implementierung) wird benötigt, um `sudo vhost ...` aus PHP heraus aufzurufen. `proc_open` darf hier **nicht** pauschal deaktiviert werden. Konsequenz: Die Absicherung muss an anderer Stelle ansetzen –

- striktes `open_basedir`,
- eng gefasste sudoers-Regel (siehe unten),
- Eingabevalidierung aller Parameter, die an den `vhost`-Aufruf weitergereicht werden (Escaping/Allowlisting von Domainnamen, IPs, Pfaden, kein direktes Durchreichen von Nutzereingaben an eine Shell),
- alle anderen, für den Panel-Betrieb nicht benötigten Funktionen (`system`, `passthru`, `pcntl_*`, `popen`) weiterhin deaktivieren, da sie nicht gebraucht werden.

Quelle: php.net (disable_functions ist Teil von `ini.core.php`); cyberciti.biz/stackharbor.com als ergänzende Praxisquellen zur Funktionsliste.

## Session-Einstellungen

- `session.cookie_httponly = 1`: verhindert JavaScript-Zugriff auf das Session-Cookie, mindert Session-Diebstahl per XSS.
- `session.cookie_samesite = Strict` (oder mindestens `Lax`): Cookie wird bei Cross-Site-Requests nicht mitgesendet, mindert CSRF. Seit PHP 7.3 direkt per INI/`session_set_cookie_params()`-Array konfigurierbar; ohne Angabe wird gar kein SameSite-Attribut gesetzt.
- `session.cookie_secure`: nur sinnvoll, wenn die Verbindung tatsächlich TLS-terminiert ist. Läuft das Panel ausschließlich auf `127.0.0.1:8080` ohne eigenes TLS, würde `cookie_secure=1` das Cookie u. U. gar nicht setzen – abhängig davon, ob ein vorgeschalteter Reverse-Proxy TLS terminiert. Diese Einstellung ist projektabhängig und hier nicht abschließend zu beurteilen (unbelegt für die konkrete Deployment-Variante).

Quelle: php.net/manual/en/function.session-set-cookie-params.php; wiki.php.net/rfc/same-site-cookie.

## expose_php

`expose_php = Off` unterdrückt den `X-Powered-By`-Header, der sonst die PHP-Version preisgibt und Angreifern das gezielte Suchen nach bekannten Schwachstellen erleichtert. Betrifft nur den von PHP selbst gesetzten Header; zusätzlich sollte nginx per `fastcgi_hide_header` etwaige von der Applikation gesetzte Header gleichen Namens ebenfalls unterdrücken (siehe unten).

Quelle: php.net (expose_php ist Teil von `ini.core.php`); perishablepress.com als Praxisquelle.

## FPM-Pool-Einstellungen

Für eine selten genutzte Admin-Oberfläche mit wenigen gleichzeitigen Nutzern:

- `pm = ondemand` statt `dynamic`: Worker-Prozesse werden nur bei Bedarf gestartet und nach Leerlauf (`pm.process_idle_timeout`) wieder beendet – reduziert die Angriffsfläche durch dauerhaft laufende Prozesse und spart Ressourcen für ein Tool, das nicht permanent gebraucht wird.
- `pm.max_children` niedrig halten (z. B. 2–4), da kein Massentraffic zu erwarten ist.
- Unix-Socket statt TCP-Port verwenden, kombiniert mit `listen.owner`/`listen.group`/`listen.mode`, um den Socket ausschließlich für den nginx-Worker-Nutzer freizugeben:

```ini
listen = /run/php/php8.5-fpm-vhost-admin.sock
listen.owner = www-data
listen.group = www-data
listen.mode  = 0660
```

- Eigener, dedizierter Pool statt Verwendung eines geteilten Standard-Pools – falls auf demselben Host weitere PHP-Anwendungen laufen, verhindert ein eigener Pool (mit eigenem User falls möglich), dass ein kompromittiertes zweites PHP-Projekt denselben FPM-Prozessraum/Socket nutzt.

Quelle: allgemeine PHP-FPM-Poolkonfiguration, mehrere Praxisquellen (ma.ttias.be, tideways.com); die konkreten Direktivnamen (`pm`, `listen.owner`, `listen.mode`) sind Standard-FPM-Direktiven.

## nginx-seitige Absicherung

- Panel nur an `127.0.0.1:8080` binden (`listen 127.0.0.1:8080;`), kein `0.0.0.0` – bereits laut Projektbeschreibung so umgesetzt.
- `fastcgi_hide_header X-Powered-By;` (und ggf. weitere von PHP/Frameworks gesetzte Header) ergänzend zu `expose_php = Off`, damit auch bei versehentlich reaktiviertem `expose_php` nichts durchsickert.
- `fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;` korrekt setzen und mit `try_files $uri =404;` kombinieren, damit nginx nicht jede beliebige `.php`-URL an FPM weiterreicht, auch wenn die Datei nicht existiert (klassische Path-Info-/Fehlkonfigurationsklasse von Schwachstellen bei FastCGI).

Quelle: digitalocean.com (FastCGI-Proxying-Grundlagen), computingforgeeks.com (`fastcgi_hide_header`-Praxis) als ergänzende, nicht-offizielle Quellen; das Grundprinzip von `try_files` gegen Fehlrouting ist in der nginx-Community weit dokumentiert, hier ohne offizielle nginx.org-Einzelquelle referenziert.

## Risiken von sudoers-Regeln für www-data

Eine `NOPASSWD`-sudoers-Regel für `www-data` auf ein root-CLI ist der kritischste einzelne Punkt der gesamten Architektur: Jede Code-Ausführungs- oder Command-Injection-Schwachstelle im PHP-Panel führt direkt zu Root-Rechten, ohne dass ein Passwort als zusätzliche Hürde existiert. Wiederkehrende Empfehlungen aus mehrereren unabhängigen Quellen zur sudoers-Härtung:

- Command so eng wie möglich fassen: **exakter, absoluter Pfad** zum `vhost`-Binary, keine Wildcards wie `ALL=(ALL) NOPASSWD: /usr/local/bin/vhost *`, die beliebige Argumente erlauben.
- Wenn möglich, pro erlaubtem Subcommand eine eigene Regel/einen eigenen `Cmnd_Alias`, statt eines allgemeinen Aufrufs mit freien Parametern.
- `vhost` selbst muss sämtliche Argumente streng validieren (Domainnamen-Format, Pfad-Traversal ausschließen, keine Shell-Metazeichen durchreichen), weil sudo selbst keine Shell aufruft und Argumente nur so sicher sind, wie das aufgerufene Programm sie behandelt.
- Sudo-Logging/Auditd aktivieren, damit jede Nutzung des Wegs `www-data → root` nachvollziehbar ist.
- Das Konto/den Dienst als reinen Systemaccount ohne interaktive Shell führen (bereits durch `www-data` als Standard-Webserver-User weitgehend gegeben).

Quelle: decryptiondigest.com, dohost.us (Praxisquellen zu NOPASSWD-Risiken und Härtung; keine offizielle sudo-Dokumentation direkt zitiert, daher als Sekundärquellen markiert).

## Ableitungen für vhost-admin

- BUG: Falls `disable_functions` aus einer generischen Hardening-Vorlage übernommen wurde, sollte konkret geprüft werden, ob `proc_open` (und ggf. das für den `vhost`-Aufruf genutzte `exec`/`shell_exec`) versehentlich mit deaktiviert wurde – das würde das gesamte Admin-CLI stillschweigend funktionsunfähig machen.
- FEATURE: `pm = ondemand` statt `dynamic` für den FPM-Pool des Panels einführen, passend zum Nutzungsprofil „selten, wenige Admins".
- OPTIMIZE: `expose_php = Off` plus `fastcgi_hide_header X-Powered-By;` in nginx ergänzen, falls noch nicht vorhanden – geringer Aufwand, reduziert Fingerprinting.
- FEATURE: `session.cookie_httponly` und `session.cookie_samesite` explizit setzen (nicht auf PHP-Defaults verlassen, die ohne Angabe kein SameSite-Attribut setzen).
- BUG (potenziell): Die sudoers-Regel für `www-data → vhost` sollte konkret daraufhin überprüft werden, ob sie einen exakten Pfad ohne Wildcard-Argumente verwendet – eine zu weit gefasste Regel wäre der größte Single Point of Failure der gesamten Architektur.
- OPTIMIZE: Unix-Socket mit `listen.owner/group/mode` statt TCP für die FPM-Kommunikation verwenden, falls aktuell TCP genutzt wird.

## Quellen

- https://www.php.net/manual/en/ini.core.php
- https://www.php.net/manual/en/function.session-set-cookie-params.php
- https://wiki.php.net/rfc/same-site-cookie
- https://www.bencteux.fr/posts/open_basedir/
- https://stackharbor.com/en/knowledge-base/php-security-hardening-runtime/
- https://perishablepress.com/expose-php/
- https://ma.ttias.be/a-better-way-to-run-php-fpm/
- https://www.digitalocean.com/community/tutorials/understanding-and-implementing-fastcgi-proxying-in-nginx
- https://computingforgeeks.com/how-to-hide-x-powered-by-x-cf-powered-by-php-headers-in-nginx/
- https://www.decryptiondigest.com/blog/linux-sudo-sudoers-security-hardening-privilege-escalation-guide
- https://dohost.us/index.php/2026/03/08/beyond-passwordless-sudo-the-security-risks-of-nopasswd-and-how-to-mitigate-them/
