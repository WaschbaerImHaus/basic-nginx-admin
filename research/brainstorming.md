Stand: 2026-09-17

# Brainstorming: entfernte Begriffe und vhost-admin

Freie Assoziation zwischen fünf scheinbar fachfremden Begriffen und dem Projekt. Kein Anspruch auf Vollständigkeit, keine belastbaren Tool-Aussagen – reine Ideenskizze mit je einer konkreten Umsetzungsidee (Feature, Test oder Betriebsregel) pro Begriff.

Der Sinn der Übung: vhost-admin ist ein kleines, klar umrissenes Werkzeug (nginx-vHosts, Zertifikate, Basic Auth), aber die Prinzipien dahinter – kontrollierte Änderungen, Misstrauen gegenüber scheinbar sicheren Quellen, Zeitfenster, Haltbarkeit, Grenzen – tauchen in ganz anderen Domänen in ähnlicher Form auf. Die fünf Begriffe unten dienen als Denkanstoß, nicht als fertige Roadmap.

## Immutable Infrastructure

Immutable Infrastructure bedeutet, dass laufende Systeme nach dem Deployment nicht mehr manuell verändert, sondern bei jeder Änderung komplett neu erzeugt und ausgetauscht werden – das verhindert Configuration Drift.

- **Bezug zum Projekt**: Eine nginx-vHost-Konfiguration sollte nie von Hand nachbearbeitet werden, sondern immer vollständig aus der SQLite-Datenbank (der eigentlichen „Source of Truth") neu generiert werden. Manuelle Edits an generierten Dateien wären das Äquivalent zu einem manuell gepatchten Server – unsichtbar für das nächste `vhost`-Kommando und eine Quelle von Drift.
- **Grenze der Analogie**: Anders als bei „echten" immutable Deployments (Container-Images, VM-Snapshots) lässt sich eine nginx-Konfigurationsdatei technisch jederzeit von Hand öffnen und ändern. Immutable Infrastructure ist hier also eher eine Betriebsdisziplin als eine technische Garantie.
- **Konkrete Idee (FEATURE)**: Ein Befehl `vhost rebuild-all`, der sämtliche nginx-Configs aus der DB komplett neu schreibt (nicht patcht) und dadurch jede manuelle Abweichung beim nächsten Lauf automatisch wieder einkassiert. Optional: ein `vhost diff`, das die tatsächlich auf der Platte liegende Config mit der aus der DB generierten vergleicht und Drift meldet, bevor es zum Problem wird.

## Zero Trust

Zero Trust („never trust, always verify", NIST SP 800-207) verzichtet auf implizites Vertrauen allein aufgrund von Netzwerkposition oder früherer Authentifizierung.

- **Bezug zum Projekt**: Nur weil eine Anfrage von `127.0.0.1` kommt, heißt das nicht, dass sie vertrauenswürdig ist – das PHP-Panel selbst könnte kompromittiert sein (z. B. durch eine Lücke in einer Abhängigkeit) und würde dann als „lokal, also vertrauenswürdig" erscheinen, obwohl der eigentliche Angreifer entfernt sitzt und nur über das Panel agiert.
- **Grenze der Analogie**: Zero Trust im NIST-Sinn adressiert primär Netzwerkzugriff zwischen Diensten und Nutzern; die Lücke hier liegt eine Ebene tiefer, im Vertrauen zwischen dem PHP-Prozess und dem root-CLI innerhalb derselben Maschine. Die Grundidee („Standort allein ist kein Beweis für Vertrauenswürdigkeit") trägt trotzdem.
- **Konkrete Idee (BETRIEBSREGEL)**: Kritische, zerstörerische Aktionen (Domain löschen, htpasswd komplett ersetzen, Zertifikat widerrufen) verlangen im Panel eine zusätzliche Re-Authentifizierung oder Bestätigung, statt sich allein auf die bestehende Session und die Tatsache zu verlassen, dass der Request „von innen" kommt.

## Bakery/Bäckerei

Zertifikate werden „gebacken" (ausgestellt) und haben eine begrenzte Haltbarkeit, bevor sie „altbacken" (abgelaufen) sind – bei Let's Encrypt aktuell 90, künftig 45 Tage. Ein Bäcker plant seinen Tag danach, was wann frisch sein muss, nicht danach, wann etwas zufällig schlecht wird.

- **Bezug zum Projekt**: Der certbot-Renewal-Timer übernimmt strukturell die Rolle des Backplans – er arbeitet vorausschauend nahe am Ablaufdatum, nicht reaktiv erst nach dem Verfall.
- **Grenze der Analogie**: Anders als beim Brot gibt es bei Zertifikaten keine Qualitätsminderung vor dem Ablauf – ein Zertifikat ist bis zur letzten Sekunde „genauso frisch" wie am ersten Tag. Die Analogie trägt nur für die Terminplanung, nicht für graduelle Verschlechterung.
- **Konkrete Idee (FEATURE)**: Ein „Backplan"-Widget im Panel, das für alle vHosts mit öffentlichem Zertifikat anzeigt, wann die nächste Erneuerung ansteht, mit optischer Warnstufe, wenn ein Zertifikat näher am Ablauf ist als es der reguläre Renewal-Rhythmus vorsähe – ein Frühwarnsystem statt reinem Vertrauen auf den systemd-Timer.

## Schachuhr

Eine Schachuhr verteilt ein festes Zeitbudget auf zwei Parteien, die abwechselnd am Zug sind, und macht Verzögerung sichtbar und begrenzt.

- **Bezug zum Projekt**: Das passt zu den Rate-Limits von Let's Encrypt: Jede Woche steht dem Projekt nur ein begrenztes „Zeitguthaben" an Zertifikatsanfragen pro Domain zur Verfügung (siehe `certbot-webroot.md`), und wer es unbedacht verbraucht – etwa durch wiederholte fehlgeschlagene Versuche beim Debuggen –, steht am Ende ohne Zug da, während das Rate-Limit-Fenster weiterläuft.
- **Grenze der Analogie**: Bei der Schachuhr verlieren beide Spieler symmetrisch Zeit; bei Let's Encrypt ist nur eine Seite (das Projekt) durch das Limit eingeschränkt, die CA selbst „verliert" nichts. Die Analogie beschreibt also nur die Knappheit, nicht die Symmetrie.
- **Konkrete Idee (TEST)**: Ein Integrationstest, der eine Serie von `vhost add-domain`-Aufrufen gegen die certbot-Staging-Umgebung simuliert und prüft, dass Wiederholungsversuche bei Fehlern nicht das reale Produktionslimit gefährden würden – quasi ein Test, der die eigene „Schachuhr" nie unbeabsichtigt ablaufen lässt.

## Gartenzaun

Ein Gartenzaun markiert unmissverständlich, was drinnen und was draußen ist – er ist die physische Entsprechung von `allow`/`deny` plus `auth_basic` um einen vHost, aber auch von `open_basedir` um den Dateizugriff von PHP.

- **Bezug zum Projekt**: Ein Zaun mit einer offenen Lücke ist wertlos, ganz gleich wie stabil der Rest ist – genau wie eine vergessene `deny all;`-Zeile eine sonst korrekte Zugriffskontrolle aushebelt (siehe Vererbungsregel in `nginx-basic-auth.md`).
- **Grenze der Analogie**: Ein Gartenzaun schützt passiv und gleichbleibend; nginx-Zugriffsregeln werden dagegen bei jedem Reload neu ausgewertet und können durch eine einzelne fehlerhafte Änderung unbemerkt komplett verschwinden – der „Zaun" ist software-definiert und damit fragiler als sein physisches Vorbild.
- **Konkrete Idee (BETRIEBSREGEL/FEATURE)**: Jeder neu angelegte vHost bekommt per Default einen geschlossenen Zaun (kein `allow all`, sondern explizite IP-Liste oder Basic Auth), und das `vhost`-CLI warnt oder verweigert das Anlegen eines vHosts ganz ohne jegliche Zugriffsbeschränkung, statt „offen" als stillen Default zuzulassen.

Gemeinsamer Nenner aller fünf Ideen: Keine erzwingt eine große Umbaubühne. Jede lässt sich als kleiner, abgegrenzter Schritt umsetzen (ein neuer CLI-Unterbefehl, eine zusätzliche Bestätigung, ein Widget, ein Test, eine Default-Änderung) und passt damit zur bestehenden Größe und Philosophie von vhost-admin als schlankem Werkzeug statt großem Framework.

## Quellen

- https://www.techtarget.com/searchitoperations/definition/immutable-infrastructure
- https://spacelift.io/blog/what-is-immutable-infrastructure
- https://blog.riskrecon.com/understanding-nist-800-207
- https://www.crowdstrike.com/en-us/cybersecurity-101/zero-trust-security/

Hinweis: Die Abschnitte „Bakery/Bäckerei", „Schachuhr" und „Gartenzaun" sind freie Analogien ohne externe Quelle (unbelegt, da keine Tatsachenbehauptung über nginx/certbot/PHP-Verhalten, sondern Bildsprache). Die zugrundeliegenden technischen Fakten zu Rate-Limits und Renewal stammen aus `certbot-webroot.md`.

Zwei der fünf Begriffe (Immutable Infrastructure, Zero Trust) sind etablierte Fachbegriffe mit externer Quelle; die drei Bildbegriffe (Bäckerei, Schachuhr, Gartenzaun) dienen ausschließlich der Veranschaulichung und wurden bewusst nicht mit Fachliteratur unterlegt, um keine Scheingenauigkeit vorzutäuschen.
