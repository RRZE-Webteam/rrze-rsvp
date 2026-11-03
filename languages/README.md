# Übersetzungen pflegen

Die PO-Dateien enthalten die gepflegten Übersetzungen. PHP lädt die zugehörige
MO-Datei; der RSVP-Editor lädt zusätzlich einen Jed-Katalog mit dem registrierten
Script-Handle `rrze-rsvp-editor-script`. Die deutsche Oberfläche verwendet die
Sie-Ansprache. Weitere Begriffe stehen in [wording.md](wording.md).

## Quellen und Kataloge aktualisieren

Voraussetzungen: die mit `npm ci` installierten Entwicklungsabhängigkeiten,
WP-CLI mit dem I18n-Paket und GNU Gettext (`msgfmt`). Im Plugin-Verzeichnis:

```sh
wp i18n make-pot . languages/rrze-rsvp.pot --domain=rrze-rsvp --exclude=build,tests,mail,tools
node tools/build-block-translations.mjs
```

WP-CLI erfasst PHP, JavaScript und `block.json`. Das ergänzende Skript liest die
TS-/TSX-Dateien des RSVP-Blocks mit dem TypeScript-Parser und ergänzt fehlende
Einträge in der POT-Datei. Es berücksichtigt `__`, `_x`, `_n` und `_nx`, deren
Import-Aliasse sowie die Übersetzungskontexte aus `block.json`.

Bei neuen Editor-Texten meldet das Skript fehlende oder als unsicher (`fuzzy`)
markierte Übersetzungen. Ergänzen Sie diese in der jeweiligen PO-Datei. Die
bestehenden Übersetzungen bleiben erhalten; auch Platzhalter wie `%s` oder `%d`
müssen in der Übersetzung erhalten bleiben. Danach:

```sh
msgfmt --check languages/rrze-rsvp-de_DE.po -o languages/rrze-rsvp-de_DE.mo
node tools/build-block-translations.mjs
node tools/build-block-translations.mjs --check
```

Das Skript verarbeitet alle vorhandenen `rrze-rsvp-<locale>.po`-Dateien und
erzeugt daraus `rrze-rsvp-<locale>-rrze-rsvp-editor-script.json`. Momentan ist
nur `de_DE` vorhanden. Es verändert die PO-Dateien nicht. `--check` prüft die
Vollständigkeit der POT-Datei und die Aktualität der Jed-Kataloge ohne Dateien
zu schreiben. Die Ausgabe ist bei unveränderten Quellen und Übersetzungen
reproduzierbar.

PO, MO, POT und Jed-Kataloge werden zusammen ausgeliefert. Node.js, TypeScript,
WP-CLI und Gettext sind nur zur Entwicklung erforderlich; zur Laufzeit lädt
WordPress die fertigen Kataloge.
