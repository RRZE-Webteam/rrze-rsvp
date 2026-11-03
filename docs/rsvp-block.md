# RSVP-Block

Der dynamische Block `rrze/rsvp` bietet einen Inserter-Eintrag **RSVP**. Nach dem
Einfügen wählen Redakteure die Buchungsart und ein vorhandenes Angebot:

| Buchungsart | Ressource | Ausgabe |
| --- | --- | --- |
| Kursanmeldung (`course`) | Kurs | Einzelne Kurstermine und Anmeldung |
| Sprechstundentermin (`consultation`) | Raum im Modus `consultation` | Kalender und Zeiten ohne Platzauswahl |
| Platzbuchung (`seat`) | Raum im Modus `reservation` oder `no-check` | Kalender, Zeiten und Plätze |

Ein Wechsel der Buchungsart leert die Auswahl. Die zentrale Konfiguration des
Angebots bleibt erhalten. Über „Angebot bearbeiten“ wird dessen Verwaltung in
einem neuen Tab geöffnet. Verfügbarkeitslisten und QR-Codes bleiben Shortcodes.

Die Vorschau liest echte Angebotsdaten, enthält aber keine aktiven Buchungs- oder
Loginformulare. Kurse zeigen höchstens fünf kommende veröffentlichte Termine;
Räume den aktuellen Monat und Zeiten seines ersten buchbaren Tages. Die Vorschau
führt weder Anmeldung noch Buchung noch Mailversand aus. Entwürfe können mit den
passenden Bearbeitungsrechten gewählt werden, öffentlich erscheinen nur
veröffentlichte Angebote ohne Passwort. Eine ungültig gewordene Auswahl bleibt
im Editor erhalten und erhält einen Hinweis.

## Entwicklung

```sh
npm ci
npm run typecheck
npm run build:block
npm run dev:block
npm run build
npm test
composer test
```

`build:block` prüft TypeScript, baut die Editor-Dateien mit WordPress Scripts und
erzeugt den JS-Übersetzungskatalog. `build` erzeugt zunächst die bestehenden
Webpack-Assets, anschließend den Block und zuletzt die Maizzle-Mailvorlage.
`start` beobachtet die bestehenden Assets; `dev:block` beobachtet nur den Block.
Beide Watch-Builds können gleichzeitig laufen. Das bisherige Webpack erhält
`build/blocks/` und `build/email/`; der Block-Build bereinigt ausschließlich
`build/blocks/`. Nach einem Watch-Test für die Auslieferung erneut `npm run build`
ausführen.

Quellen: `src/blocks/rsvp/`; Artefakte: `build/blocks/rsvp/`. Die Source-Metadaten
referenzieren `index.tsx`; WordPress Scripts schreibt daraus `index.js` in die
Build-Metadaten. React und WordPress-Pakete werden externalisiert und über
`index.asset.php` aus WordPress geladen. Mindestversion bleibt WordPress 6.7.
Node.js wird nur zur Entwicklung benötigt. Das Icon ist das vom Projekt
bereitgestellte Google-Material-Symbol in `assets/icons/rsvp.svg`; die TSX-Version
verwendet dessen ViewBox und `currentColor`.

[Übersetzungen pflegen](../languages/README.md)

## Datenzugriff und Instanzen

Private GET-Routen unter `rrze-rsvp/v1/editor`:

- `resources?mode=course|consultation|seat&search=…&page=1`: höchstens 20 passende
  Titel je Seite, mit Prüfung der Verwaltungsberechtigung und jedes einzelnen
  Objekts. Die Suche bezieht sich auf Titel.
- `preview?mode=…&id=…`: passive Vorschau ohne Teilnehmerdaten oder Aktionstoken.

Die CPTs werden nicht für die allgemeine REST-Ausgabe freigegeben. Abgebrochene
und überholte Anfragen können neue Auswahlen nicht ersetzen. Ein Request-Schlüssel
verhindert auch vor dem nächsten React-Effect veraltete Inhalte und falsche
Leerzustände.

Gespeichert werden `mode`, `resourceId` und eine UUID `instanceId`. Beim
Duplizieren erhält die Kopie eine neue UUID. Wiederholte Ausgaben desselben
synchronisierten Blocks erhalten zusätzlich einen Render-Suffix. Rekursive
Cache-Erkennung verfolgt verschachtelte und synchronisierte Blöcke mit
Zyklenschutz.

Raumformulare übertragen Quellseite, Instanz, Modus und Ressource. Kontext- und
Formular-Nonces sind an diese Werte gebunden. Formulare und AJAX-Fragmente erhalten
instanzbezogene IDs und passende Label-/ARIA-Verweise. Globale `room_id`-Parameter
können die Blockauswahl nicht ersetzen. Veröffentlichungsstatus, Raum-/Platzbindung,
Authentifizierung, Buchungszeitraum und aktuelle Verfügbarkeit werden beim
Absenden geprüft. Der Endzeitpunkt stammt aus dem tatsächlich verfügbaren Intervall.

Rücksprünge führen zur lokalen Quellseite und ihrem Block. Fehlerdaten werden vor
dem Rendern einmal konsumiert und nur ihrer Instanz übergeben. Belege sind an
Buchungs-ID und Instanz gebunden. LDAP-Anmeldung wird vor dem Rendern verarbeitet;
das Lesen der Identität meldet nicht ab. Logout erfolgt einmal nach erfolgreicher
Raumbuchung. Auch ältere Raumbelege verlangen jetzt eine an die Buchungs-ID
gebundene Nonce; frühere kurzlebige Beleg-URLs werden damit ungültig.

Bestehende Shortcodes und `rrze-rsvp/course-booking` bleiben verwendbar. Der alte
Kursblock ist aus dem Inserter verborgen und kann ausdrücklich in den neuen Block
umgewandelt werden. Es findet keine automatische Inhaltsmigration statt.

## Prüfung

Automatisch: strenge Typprüfung, Build, 30 JavaScript-Tests, vorhandene PHP-Suites,
Ressourcen-/Vorschau-/Kontexttests und 23 LDAP-Session-Assertions. Der Sessiontest
verwendet echte temporäre Session-Dateien und lokale LDAP-Mocks ohne Netzwerk.

Die lokale WordPress-Integration wird separat ausgeführt:

```sh
wp --skip-plugins --skip-themes --url=https://nat.multisite.localhost:8890 \
  eval-file tests/block-integration.php
```

Sie registriert RSVP isoliert, erstellt synthetische Angebote und Buchungen,
fängt sämtlichen Mailversand ab und entfernt ihre Datensätze in `finally`.
Geprüft werden echte Leser, doppelte und gemischte Instanzen, ID-/ARIA-Zuordnung,
AJAX und Buchungshorizont, manipulierte/entfernte Kontextfelder, Fehler- und
Belegzuordnung, wiederholte Buchungen sowie Check-in-Ausgabe. Nur in einer lokalen
Testinstallation ausführen.

Am 03.11.2026 geprüft: beide tatsächlichen Watch-Prozesse mit je einem erneuten
Build; Frontend-, Block- und Mail-Ausgaben bleiben gegenseitig erhalten.
Firefox: Einfügen, Moduswahl, Raumsuche per Tastatur, Raum-/Kursvorschau,
Moduswechsel, leerer Sprechstundenmodus, Speichern/Neuladen, deutsche Texte,
Duplizieren und mobile Editoransicht. Einträge der Theme-Listen werden in der
passiven Kursvorschau gezielt zurückgesetzt.

Nicht als reale End-to-End-Prüfung abgenommen: Anmeldung und Rückkehr gegen einen
externen SSO-/LDAP-Dienst. Die lokale SSO-Anmeldung in den WordPress-Editor ersetzt
diesen Buchungsablauftest nicht.
