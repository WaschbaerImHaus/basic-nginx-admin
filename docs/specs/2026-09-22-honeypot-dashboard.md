# Spec: Honigtopf-Ansicht unter bienchen.mfsvr.de

Stand: 2026-09-22 · Grundlage: `access.log` und `error.log` von `mfsvr.de`,
ausgewertet am 2026-09-22 (529 Anfragen des Vortags)

## Zweck

`mfsvr.de` liefert eine statische Seite aus und hat seit dem 2026-09-22 die Funktion
eines Honigtopfs: Die `robots.txt` gibt nur `/` und das Favicon frei und nennt `/admin/`
ausdrücklich als ausgeschlossen. Wer darüber hinausgeht, tut das absichtlich. Diese
Ansicht macht sichtbar, **wer das tut, womit und wonach gesucht wird**.

Sie ist kein Zugriffszähler. Besucherzahlen, Verweildauer und Herkunftsländer gehören
nicht hierher.

---

## Der Befund, der alles andere bestimmt: es gibt keine Client-IP

In den Logs steht bei **jeder** Anfrage von aussen dieselbe Adresse:

```
528 10.200.0.1
  1 127.0.0.1
```

`10.200.0.1` ist das NAT-Gateway des LXC. Der Verkehr wird auf dem Weg hierher
umgeschrieben; die tatsächliche Gegenstelle steht nirgends. Es liegt auch kein
`X-Forwarded-For` an – davor sitzt kein Proxy, sondern eine Adressumsetzung.

**Folge für diese Ansicht:** Jede Kennzahl, die auf der Client-Adresse beruht, wäre
erfunden. Also keine „eindeutigen Besucher", keine „Top-Angreifer", keine Sperrlisten
nach IP, kein fail2ban. Unterschieden wird stattdessen nach **Verhalten**: Werkzeug,
gesuchte Pfade, Zeitmuster, Protokolltreue.

Das ist keine Notlösung. Bei einem Honigtopf ist die Frage „womit wird gesucht und
wonach" ohnehin ergiebiger als „von wo".

Was nötig wäre, um Adressen zu bekommen: PROXY-Protokoll oder ein vorgeschalteter Proxy,
der `X-Forwarded-For` setzt – beides gibt es hier nicht und beides wäre ein Eingriff in
die Netzstruktur, nicht in diese Anwendung. Sollte sich das ändern, kommen
adressbezogene Kacheln dazu; bis dahin sagt die Ansicht ausdrücklich, dass sie keine
kennt.

---

## Kacheln

### 1. Tagesbilanz

Anfragen, Antwortverteilung, Vergleich zum Vortag. Aus den echten Daten:

| Antwort | Anteil | Bedeutung hier |
|---|---|---|
| `401` | 435 | Verzeichnisschutz war aktiv – entfällt künftig, der Honigtopf ist offen |
| `404` | 67 | Sondierung: gesucht wurde etwas, das es nie gab |
| `400` | 14 | Es war gar kein HTTP (siehe Kachel 4) |
| `200` | 1 | Die Seite selbst |

Die Zahl, auf die es ankommt, ist **404 je Tag**: Sie misst die Sondierung direkt.

### 2. Wonach gesucht wird

Die 404-Pfade, gruppiert nach Beute. Aus den echten Daten:

| Gruppe | Beispiele aus dem Log |
|---|---|
| Zugangsdaten | `/twilio.env`, `/.env`, `/wp-config.php`, `/wp-config.php.backup` |
| Konfiguration | `/web.config`, `/config.json` |
| Paketdateien | `/yarn.lock` |
| Entwicklungsreste | `/tests/`, `/test/phpinfo/`, `/tmp/` |
| Verwaltung | `/admin/`, `/phpmyadmin` |

Die Gruppe ist die eigentliche Aussage: Nach Zugangsdaten wird gesucht, nicht nach
Lücken in einer Anwendung – hier steht ja nur eine statische Seite.

### 3. Womit gesucht wird

User-Agent-Familien statt einzelner Zeichenketten. Aus den echten Daten:

```
95  Firefox/4.0 (2011)          ┐
95  Chrome/6.0.453.1 (2010)     │ viermal 95 – dieselbe Quelle,
95  Chrome/48.0.2564.103 (2016) │ die ihre Kennung durchwechselt
95  Firefox/22.0 (2013)         ┘
47  libredtail-http             ← nennt sich beim Namen
14  Software Security Research  ← Forschungsscanner der RUB, nennt eine URL
```

Vier angebliche Browser, jeder genau 95-mal, alle mit veralteten Versionen: Das ist ein
Werkzeug, das seine Kennung durchtauscht. Die Kachel muss das zeigen, indem sie
**gleiche Häufigkeiten** nebeneinanderstellt – nicht, indem sie nach „bekannten Bots"
sucht.

Drei Klassen: *nennt sich* (libredtail, Forschungsscanner), *tarnt sich* (alte
Browserkennungen in gleicher Zahl), *nennt nichts* (`-`, 20 Anfragen).

### 4. Was gar kein HTTP war

Die `400`-Antworten sind die interessanteste Kachel, weil sie keine Sondierung von
Webinhalten sind, sondern von Diensten:

```
SSH-2.0-Go                        ← sucht einen SSH-Dienst auf Port 443
MGLNDD_87.106.116.167_443         ← Kennung eines Portscanners, enthält die eigene IP
CONNECT                           ← sucht einen offenen Proxy
\x10\x00\x00\x00\x01...           ← Binärprotokoll, kein HTTP
```

Diese Zeilen stehen mit `"-"` als Status oder `400` im Log und fallen bei jeder
gewöhnlichen Auswertung hinten runter. Für einen Honigtopf sind sie Kernbestand.

### 5. Das robots.txt-Signal

Die eigentliche Falle. Drei Zahlen:

- Wie oft wurde `/robots.txt` geholt?
- Wie oft wurde danach `/admin/` besucht – also genau der dort ausgeschlossene Pfad?
- Wie lang war die Zeitspanne dazwischen?

Ein Abruf von `/admin/` **ohne** vorheriges `robots.txt` ist Rateverhalten. Einer
**mit** ist ein bewusster Verstoss und die schärfste Aussage, die diese Seite liefern
kann. Damit das messbar ist, wird `/robots.txt` seit dem 2026-09-22 protokolliert
(vorher stand dort `access_log off`).

### 6. Anmeldeversuche

Aus dem `error.log`, solange ein Host Verzeichnisschutz hat:

```
user "x" was not found in "/etc/nginx/auth/mfsvr.de.htpasswd"
```

Zwei Zahlen: versuchte Benutzernamen und Versuche je Name. Die Namen selbst sind
aufschlussreich (`admin`, `root`, der Domainname).

### 7. Zeitprofil

Anfragen je Stunde. Aus den echten Daten:

```
06 ▏4     07 ███████ 101    08 ▏3     09 ▏3     10 ▏7
11 ███████████ 155   15 ▏4     16 █ 18    17 ▏6     18 ███████ 102
19 ▏9     20 ▏1     21 ▏6     22 ▏5     23 ███████ 105
```

Vier klare Wellen (07, 11, 18, 23) mit je rund 100 Anfragen und Ruhe dazwischen. Das ist
kein Publikum, das ist ein Zeitplan. Die Kachel zeigt den Tagesverlauf als Balken; die
Aussage ist die **Form**, nicht die Höhe.

---

## Aufbau der Seite

Dieselbe Gestaltung wie die Verwaltungsoberfläche: volle Breite, Systemschriften, nichts
aus dem Netz, helles und dunkles Schema nach Systemeinstellung. Maschinenwerte in
Festbreitenschrift.

```
┌──────────────────────────────────────────────────────────────────────┐
│ bienchen · Honigtopf mfsvr.de                        22.09.  ▾ Tag   │
├──────────────────────────────────────────────────────────────────────┤
│ ┌── Tagesbilanz ──────┐ ┌── Zeitprofil ─────────────────────────────┐│
│ │ 529 Anfragen  +12 % │ │ ▁▁▇▁▁▁█▁▁▁▇▁▁▁▇                           ││
│ │  67 Sondierungen    │ │ 00        08        16        23          ││
│ │  14 kein HTTP       │ └───────────────────────────────────────────┘│
│ └─────────────────────┘                                              │
│ ┌── Wonach gesucht wird ────────┐ ┌── Womit ────────────────────────┐│
│ │ Zugangsdaten      28  ███████ │ │ tarnt sich   380  vier Kennungen││
│ │ Entwicklungsreste 19  █████   │ │ nennt sich    61  libredtail …  ││
│ │ Konfiguration      9  ██      │ │ nennt nichts  20                ││
│ └───────────────────────────────┘ └─────────────────────────────────┘│
│ ┌── robots.txt-Signal ──────────────────────────────────────────────┐│
│ │ gelesen 3 · danach /admin/ besucht 1 · Abstand 4 s                ││
│ └───────────────────────────────────────────────────────────────────┘│
│ ┌── Kein HTTP ──────────────────────────────────────────────────────┐│
│ │ SSH-Banner 1 · Portscanner-Kennung 1 · CONNECT 1 · Binär 11       ││
│ └───────────────────────────────────────────────────────────────────┘│
└──────────────────────────────────────────────────────────────────────┘
```

Keine Landkarte, kein Tortendiagramm, keine Verlaufskurve über Wochen: Bei zwei
Quelladressen und vier Scanwellen täuschte das eine Genauigkeit vor, die die Daten nicht
hergeben.

## Datenquelle

Die Ansicht rechnet nicht selbst, sondern liest den Bericht, den die tägliche Auswertung
schreibt (`honeypot/analyse.php`, siehe unten). Grund: Die Logs gehören root und sind für
`www-data` nicht lesbar – und sollen es auch bleiben.

## Bewusst nicht enthalten

- **Sperrlisten, fail2ban:** ohne Client-IP wirkungslos.
- **Geodaten:** dito.
- **Echtzeit:** Die Auswertung läuft einmal täglich. Ein Honigtopf lebt von Mustern über
  Tage, nicht von Sekunden.
- **Köderformulare, die Zugangsdaten mitschreiben:** Wer Zugangsdaten einsammelt, betreibt
  keinen Honigtopf mehr, sondern eine Falle mit fremden Daten darin. `/admin/` bleibt eine
  schlichte Seite; die Anfrage allein ist das Signal.

---

## Umgesetzt am 2026-09-22 (Build 11)

Die Ansicht liegt unter `src/public/honeypot/` (`index.php`, `detail.php`, `bootstrap.php`,
`style.css`), die Auswertung unter `src/lib/Honeypot/`. Eingerichtet wird sie mit
`honeypot/install-dashboard.sh <ansichtshost> <honigtopf-host>`.

Alle sieben Kacheln sind gebaut. Dazu kamen fünf Detailansichten, die die Spec noch nicht
vorsah: alle gesuchten Pfade mit Filter nach Beutegruppe, alle Kennungen mit ihrer Klasse,
die Rohdaten der Nicht-HTTP-Anfragen, die Anmeldeversuche und eine Ereignisliste mit
Filtern (Sondierungen / kein Webzugriff / robots.txt und /admin) und Volltextsuche.

Zwei Annahmen der Spec haben sich an den echten Daten als falsch erwiesen:

1. **„Vortag" ist nicht gleich `access.log.1`.** logrotate schneidet um 06:20, nicht um
   Mitternacht; die Datei enthält zwei Kalendertage. Gruppiert wird deshalb nach dem
   Datum *in der Zeile*.
2. **Die Einträge kommen nicht chronologisch an.** `access.log` wird vor `access.log.1`
   gelesen. Ohne Sortierung je Tag ergab der Abstand robots.txt → `/admin/` einen
   negativen Wert (gemessen: −12543 s).

Ergänzt gegenüber der Spec: `CONNECT` zählt jetzt zu „kein Webzugriff" (syntaktisch HTTP,
sucht aber einen offenen Proxy), eine verstümmelte `GET`-Anfrage mit Status 400 dagegen
nicht – sie war HTTP, nur fehlerhaft.
