Stand: 2026-09-17

# certbot Webroot-Modus

## Funktionsweise

Der Webroot-Authenticator führt keine eigene Serverkonfiguration durch, sondern legt für den HTTP-01-Challenge lediglich Dateien unter `<webroot-path>/.well-known/acme-challenge/<token>` ab. Der bereits laufende Webserver muss diese Dateien über Port 80 ausliefern; der Let's-Encrypt-Validierungsserver ruft sie per HTTP ab. Aufruf-Beispiel:

```bash
certbot certonly --webroot -w /var/www/example.com -d example.com
```

Quelle: eff-certbot.readthedocs.io, „User Guide".

## Anforderungen

- Ein bereits laufender Webserver mit **Schreibzugriff** von certbot auf das angegebene Webroot-Verzeichnis.
- **Port 80 muss von außen erreichbar sein** (HTTP-01 läuft ausschließlich über Port 80, unabhängig vom Zielport der eigentlichen Site).
- Der **DNS-Eintrag** der Domain muss zum Zeitpunkt der Validierung auf die Server-IP zeigen, auf der certbot läuft.
- Bei mehreren Domains mit unterschiedlichen Webroots gilt: Jede Domain verwendet den **zuletzt genannten** `-w`-Wert in der Kommandozeile – die Reihenfolge von `-w`/`-d`-Paaren ist also sicherheitsrelevant für Multi-Domain-Aufrufe.

Quelle: eff-certbot.readthedocs.io, „User Guide" (Webroot plugin).

## Fallstrick: Redirect auf HTTPS

Wird der komplette HTTP-Traffic (inkl. `/.well-known/acme-challenge/`) unbedingt auf HTTPS umgeleitet, schlägt die HTTP-01-Validierung fehl, weil der Validierungsserver nur die Redirect-Antwort sieht statt der Tokendatei. Der Challenge-Pfad muss daher **vor** jeder Redirect-Regel unverschlüsselt und ohne Auth ausgeliefert werden, z. B.:

```nginx
server {
    listen 80;
    server_name example.com;

    location ^~ /.well-known/acme-challenge/ {
        auth_basic off;
        allow all;
        root /var/www/example.com;
    }

    location / {
        return 301 https://$host$request_uri;
    }
}
```

Das entspricht dem in diesem Projekt beschriebenen Setup (Location mit `auth_basic off; allow all;`). Dieses Muster wird auch von Drittquellen (z. B. linuxbabe.com) als Standardlösung empfohlen; die technische Notwendigkeit selbst ergibt sich direkt aus der Funktionsweise von HTTP-01 (siehe oben, eff-certbot-Doku).

## Renewal-Konfiguration

Für jedes Zertifikat legt certbot eine `.conf`-Datei unter `/etc/letsencrypt/renewal/<cert-name>.conf` an. Dort werden u. a. Authenticator (`webroot`), der verwendete Webroot-Pfad (`webroot_path`), Installer, Domains und alle Hook-Einstellungen gespeichert, damit `certbot renew` ohne erneute Angabe der ursprünglichen Flags funktioniert. Mit `certbot reconfigure` (ab certbot 2.3.0) lassen sich diese Parameter nachträglich ändern, inklusive Testlauf gegen die Staging-Umgebung vor dem Übernehmen.

Quelle: eff-certbot.readthedocs.io, „User Guide" (Renewal, reconfigure).

## Deploy-Hooks

Ausführbare Skripte in `/etc/letsencrypt/renewal-hooks/deploy/` werden **nach jeder erfolgreich abgeschlossenen Erneuerung** ausgeführt – ideal, um z. B. `nginx -s reload` anzustoßen. Skripte in `renewal-hooks/pre/` und `renewal-hooks/post/` laufen dagegen unbedingt, auch bei fehlgeschlagener Erneuerung. Reihenfolge innerhalb eines Verzeichnisses: alphabetisch nach Byte-Wert des Dateinamens (nicht lokalisierungsabhängig).

Fallstrick: Skripte in `renewal-hooks/deploy/` laufen automatisch nur bei `certbot renew`. Bei der **erstmaligen** Ausstellung über `certbot certonly` müssen Deploy-Aktionen (z. B. Reload) explizit über `--deploy-hook` angestoßen werden oder manuell erfolgen – sonst bleibt der erste Rollout ohne automatischen Reload.

Quelle: eff-certbot.readthedocs.io, „User Guide"; GitHub certbot/certbot#8368 (Community-Diskussion zur Deploy-Hook-Semantik, als Ergänzung, nicht als Primärquelle).

## --keep-until-expiring

Das Flag (`--keep-until-expiring`, Alias `--keep`) sorgt dafür, dass ein bestehendes, noch gültiges Zertifikat unverändert weiterverwendet wird, statt bei jedem Lauf ein neues auszustellen. Für `certbot run` bedeutet das: das aktuelle Zertifikat wird ggf. nur neu installiert, nicht neu beantragt. Nützlich für idempotente Automatisierung (z. B. ein `vhost add-domain`-Skript, das gefahrlos mehrfach aufgerufen werden kann, ohne unnötig Rate-Limits zu verbrauchen).

Quelle: eff-certbot.readthedocs.io, „User Guide".

## Rate-Limits (Stand certbot/Let's-Encrypt-Doku)

| Limit | Wert | Fenster |
|---|---|---|
| Certificates per Registered Domain | 50 | 7 Tage (rollierend, ca. 1 Zertifikat/202 Min. Erholung) |
| Duplicate Certificate (identische Domain-Menge) | 5 | 7 Tage (~1 Zertifikat/34 Std. Erholung) |
| New Registrations pro IP | 10 Accounts | 3 Stunden |
| Failed Validation pro Identifier+Account | 5 | 1 Stunde |

**Wichtig**: Erneuerungen (`renew`) zählen laut Let's Encrypt **nicht** gegen das „Certificates per Registered Domain"-Limit, sondern nur gegen das enger gefasste Duplicate-Certificate-Limit. Neue Domains/Subdomains zählen dagegen voll gegen das Domain-Limit.

Quelle: letsencrypt.org/docs/rate-limits/.

## Ausblick: Kürzere Zertifikatslaufzeiten (2026)

Let's Encrypt reduziert die Standardlaufzeit schrittweise von 90 auf 64 und dann auf 45 Tage über zwei Jahre. Das verdoppelt effektiv die Renewal-Frequenz (Erneuerung künftig eher um Tag 30 statt Tag 60), Rate-Limits selbst bleiben laut Ankündigung unverändert, weil Renewals exempt sind. ACME-Clients mit ARI-Unterstützung (Renewal Info) sollen das automatisch handhaben; certbot unterstützt ARI ab bestimmten Versionen (genaue certbot-Version für ARI-Support hier „unbelegt", nicht recherchiert).

Quelle: letsencrypt.org/2026/02/24/rate-limits-45-day-certs.

## Ausblick: Wildcard-Zertifikate / DNS-01

Wildcard-Zertifikate (`*.example.com`) sind ausschließlich über die **DNS-01-Challenge** möglich, nicht über HTTP-01/Webroot. certbot benötigt dafür ein DNS-Plugin (`certbot-dns-*`) mit API-Zugangsdaten des jeweiligen DNS-Providers, um TXT-Records automatisiert zu setzen. Für dieses Projekt wäre das ein grundsätzlicher Architekturwechsel (Zugangsdaten zu einem externen DNS-API statt reinem Webroot-Zugriff) und daher eher ein separates, klar abgegrenztes Feature.

Quelle: eff-certbot.readthedocs.io, „User Guide" (DNS-Plugins).

## Ableitungen für vhost-admin

- BUG: Wenn öffentliche vHosts standardmäßig HTTP→HTTPS redirecten, muss die Config-Generierung sicherstellen, dass die `^~ /.well-known/acme-challenge/`-Location **vor** der Redirect-Regel ausgewertet wird (nginx wertet `^~`-Locations vorrangig vor allgemeinen `location /`-Blöcken aus) – ein falsch platzierter oder fehlender `^~`-Präfix würde Renewals lautlos brechen.
- FEATURE: Da Deploy-Hooks nur bei `certbot renew` automatisch laufen, sollte `vhost`beim **Erstausstellen** eines Zertifikats explizit `--deploy-hook "systemctl reload nginx"` (oder äquivalent) mitgeben, statt sich auf das Renewal-Hook-Verzeichnis zu verlassen.
- OPTIMIZE: `vhost add-domain`/vergleichbare Befehle sollten `--keep-until-expiring` nutzen, damit ein versehentlicher Doppelaufruf (z. B. durch einen Fehler im UI oder erneuten CLI-Aufruf) nicht unnötig gegen das Duplicate-Certificate-Limit (5/Woche) läuft.
- FEATURE: Vor dem `certbot certonly`-Aufruf könnte `vhost` selbst prüfen, ob Port 80 für die Domain tatsächlich erreichbar ist und der DNS-Eintrag auf den Server zeigt, um klare Fehlermeldungen statt kryptischer certbot-Fehler zu liefern.
- OPTIMIZE: Mit Blick auf die kommende 45-Tage-Laufzeit sollte der Renewal-Timer/Systemd-Timer-Intervall so gewählt sein, dass auch bei verdoppelter Renewal-Frequenz genug Vorlauf bis zum Ablauf bleibt (certbot erneuert selbst erst nahe am Fälligkeitsdatum, aber Monitoring/Alerting im Projekt sollte das Intervall kennen).

## Quellen

- https://eff-certbot.readthedocs.io/en/stable/using.html
- https://eff-certbot.readthedocs.io/en/stable/man/certbot.html
- https://letsencrypt.org/docs/rate-limits/
- https://letsencrypt.org/2026/02/24/rate-limits-45-day-certs
- https://letsencrypt.org/2025/01/30/scaling-rate-limits
- https://www.linuxbabe.com/security/letsencrypt-webroot-tls-certificate
- https://github.com/certbot/certbot/issues/8368
