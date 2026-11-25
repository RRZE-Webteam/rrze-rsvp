# Raumbuchungen als CSV exportieren

Die Seite **RSVP → Export** steht Personen mit dem bestehenden Recht
`edit_bookings` zur Verfügung. Jede einzelne Buchung wird zusätzlich mit
`edit_post` geprüft. Die Rechte entsprechen damit der Buchungsverwaltung;
ein direkter Download-Aufruf umgeht diese Prüfung nicht. Es werden ausschließlich
Daten der aktuellen Website exportiert, auch in einer Multisite-Installation.

## Filter und Inhalt

Wählen Sie alle Räume oder einen einzelnen Raum, den Zeitraum und einen
Buchungsstatus. Voreingestellt sind die letzten 28 Kalendertage einschließlich
heute, alle Räume und alle Status. Der **Beginn** der Buchung muss im Zeitraum
liegen; beide ausgewählten Tage zählen vollständig. Sortiert wird chronologisch
oder bei aktiver Berechtigungsverwaltung nach Abteilung und danach nach Beginn.
Buchungen ohne Abteilung stehen bei dieser Sortierung am Ende.

Jede Zeile enthält Buchungs-ID, Raum, Platz, Beginn, Ende, Dauer in Minuten und
Status. Namen und E-Mail-Adressen werden nur nach Aktivieren der entsprechenden
Checkbox entschlüsselt und ergänzt. Fehlende Endzeiten und Dauern bleiben leer.
Manuelle Backend-Buchungen sind enthalten, Kursbuchungen, Entwürfe und Buchungen
im Papierkorb sind ausgeschlossen. Bereits endgültig gelöschte Raum- oder
Platzbezeichnungen bleiben leer; sie werden nicht rekonstruiert.

Ohne passende Buchungen erscheint ein Hinweis. Pro Datei sind höchstens 10.000
berechtigte, passende Buchungen erlaubt. Größere Ergebnisse werden ausdrücklich
abgewiesen; schränken Sie dann Zeitraum oder Raum ein. Die Datei wird niemals
still gekürzt.

## Abteilungen und historische Zuordnung

Die Spalte **Abteilung** erscheint nur bei aktivierten Buchungsberechtigungen,
wenn mindestens eine exportierte Buchung einen historischen Abteilungsstand
besitzt oder ein im Ergebnis enthaltener Raum aktuell Abteilungen in seiner
Berechtigungsliste zugewiesen hat.

Der Wert kommt immer aus dem verschlüsselten Stand **bei der Buchung**. Spätere
Umbenennungen, Archivierung oder das Leeren der Berechtigungsliste ändern diesen
Wert nicht. Aktuelle E-Mail-Zuordnungen werden niemals nachträglich auf alte oder
manuelle Buchungen angewendet. Ohne historischen Stand bleibt das Feld leer.

Ist die Berechtigungsverwaltung deaktiviert, entfällt die gesamte Spalte und die
verschlüsselten Abteilungsdaten werden nicht gelesen. Historische Zuordnungen
werden bei aktiver Funktion zuerst geprüft; aktuelle Berechtigungslisten sind nur
dann zum Ermitteln der Spalte erforderlich, wenn keine Buchung bereits eine
Abteilung hat. Unlesbare benötigte Daten verhindern den Download vollständig.
Stellen Sie in diesem Fall die ursprünglichen [Schlüssel](room-access.md) wieder her.
Aktuelle Listen von Räumen im Papierkorb werden nicht berücksichtigt; ihre noch
vorhandenen Buchungen und historischen Abteilungen bleiben exportierbar.

## Dateiformat und Aufbewahrung

Die CSV verwendet UTF-8 mit BOM, Semikolons und CRLF-Zeilenenden. Anführungszeichen,
Umlaute, Semikolons und Zeilenumbrüche in Werten werden korrekt maskiert. Werte,
die Tabellenprogramme als Formeln interpretieren könnten, erhalten ein führendes
Apostroph. Datum und Uhrzeit stehen als `YYYY-MM-DD HH:MM:SS` in derselben lokalen
Zeit wie in der bestehenden Buchungsverwaltung. Die Dauer ist eine Zahl in
Minuten, gegebenenfalls mit Dezimalpunkt.

Der Server bereitet die vollständige Datei vor der Ausgabe im Arbeitsspeicher
vor. Er legt keine Klartextdateien, Transients oder persistenten Export-Caches an.
Der Download erfordert eine gültige Formular-Nonce und erhält Cache-Sperrheader.
Bei Fehlern bleiben die gewählten Filter auf der Seite erhalten.

**Die Aufbewahrung ändert sich nicht:** Raumbuchungen mit einem Beginn vor mehr
als vier Wochen werden automatisch gelöscht. Stornierte Buchungen können früher
entfernt werden. Der Export kann nur noch vorhandene Datensätze liefern und ist
kein Archiv. Semester- oder Jahresauswertungen erfordern eine gesonderte Entscheidung
zur Aufbewahrung. Die alte, deaktivierte Kontaktverfolgung bleibt unverändert.

## Entwicklung und Tests

`composer test` enthält die Exportprüfungen für Filter, Einzelrechte,
Verschlüsselungsfehler, historische Abteilungen, CSV-Maskierung und die echte
10.000-Zeilen-Grenze. `npm test` prüft die bestehenden JavaScript-Funktionen.

Der lokale Datenbanktest wird ausdrücklich ausgeführt mit:

```sh
wp --skip-plugins --skip-themes --url=SITE eval-file tests/booking-export-integration.php
```

Er benötigt einen vorhandenen Website-Administrator, prüft die echten SQL-Filter,
WordPress-Rechte und verschlüsselten Daten, verschickt keine E-Mails und entfernt
seine temporären Posts im `finally`-Block. Einstellungen, Abteilungskatalog und
aktueller Benutzer werden wiederhergestellt. Verwenden Sie eine lokale Testinstallation.
