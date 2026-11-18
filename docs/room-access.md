# Buchungsberechtigungen für Räume

## Einrichtung und Bedienung

Die Funktion ist standardmäßig ausgeschaltet und gilt jeweils für eine Website,
auch innerhalb einer Multisite-Installation.

1. Richten Sie SSO oder LDAP ein und aktivieren Sie die entsprechende Loginpflicht
   in den Einstellungen des Raums.
2. Aktivieren Sie unter **Einstellungen → RRZE RSVP → Allgemeine Einstellungen**
   die Option **Buchungsberechtigungen verwalten**.
3. Öffnen Sie den Reiter **Buchungsberechtigungen**, wählen Sie einen Raum und
   tragen Sie eine erlaubte E-Mail-Adresse pro Zeile ein. Speichern Sie die Liste.

Leerzeichen am Anfang und Ende werden entfernt, Groß-/Kleinschreibung wird
vereinheitlicht und doppelte Einträge werden zusammengeführt. Ungültige Adressen
verhindern das gesamte Speichern. Domains, Wildcards und Aliasregeln werden nicht
unterstützt. Eine Person kann unabhängig in mehreren Räumen eingetragen werden.

Eine leere Liste bedeutet: Der Raum bleibt nach seinen bisherigen Regeln buchbar.
Wenn Sie alle Einträge entfernen und speichern, heben Sie seine zusätzliche
Einschränkung ausdrücklich auf. Schalten Sie die gesamte Funktion aus, bleiben die
Listen gespeichert, werden aber nicht mehr geprüft. Das Zurücksetzen der
allgemeinen RSVP-Einstellungen schaltet die Funktion ebenfalls aus und erhält die
raumbezogenen Listen. Beim erneuten Aktivieren gelten sie wieder.

## Welche Buchungen werden geprüft?

Die Liste gilt für neue Selbstbuchungen über Shortcode und Block, einschließlich
Sprechstunden, flexibler Reservierungen und Sofort-Check-in. Entscheidend ist die
E-Mail-Adresse aus der SSO- beziehungsweise LDAP-Anmeldung. Bei gleichzeitig
vorhandenen Sitzungen hat SSO Vorrang. Eine im Formular eingegebene Adresse oder
eine gewöhnliche WordPress-Anmeldung genügt nicht.

Die Berechtigung wird beim Anzeigen und erneut unmittelbar vor dem Speichern
geprüft. Auch Administratoren erhalten in öffentlichen Formularen keine Ausnahme.
Manuelle Backend-Buchungen bleiben für berechtigte Mitarbeiter möglich.
Kursanmeldungen sowie bereits bestehende Buchungen mit ihren Bestätigungs-,
Stornierungs- und Check-in-/Check-out-Aktionen werden nicht nachträglich verändert.

Entfernen Sie bei einem eingeschränkten Raum später die SSO-/LDAP-Pflicht, ist
keine weitere Selbstbuchung möglich, bis die Konfiguration korrigiert wurde.

## Verschlüsselung und Schlüsselquellen

Die Listen werden mit XChaCha20-Poly1305 über PHP Sodium verschlüsselt. Jeder
Schreibvorgang verwendet eine neue zufällige Nonce. Die Schlüsselableitung und
Authentifizierung binden die Daten an RSVP, die Website und den jeweiligen Raum.
Es besteht keine Laufzeitabhängigkeit vom Appointment-Plugin.

Beim Schreiben wird vorrangig `RRZE_APPOINTMENT_ENCRYPTION_KEY` aus `wp-config.php`
verwendet. Das Format entspricht Appointment: Base64 für 32 oder 64 zufällige
Bytes. Eine vorhandene, aber ungültige Konstante verursacht einen Fehler und wird
nicht durch einen anderen Schlüssel ersetzt.

Fehlt die Konstante, verwendet RSVP `wp_salt('auth')` als Grundlage für einen
eigenen abgeleiteten Schlüssel. Dafür müssen `AUTH_KEY` und `AUTH_SALT` gültig und
stabil in `wp-config.php` konfiguriert sein. WordPress-Ersatzgeheimnisse aus der
Datenbank werden nicht als Grundlage akzeptiert. Die Schlüssel müssen getrennt
von einem reinen Datenbank-Backup aufbewahrt werden.

Das Speicherformat enthält die verwendete Schlüsselquelle. Wenn Sie später die
Appointment-Konstante ergänzen, bleiben bestehende Salt-Listen lesbar. Erst beim
nächsten ausdrücklichen Speichern einer solchen Liste wird sie mit der Konstante
neu verschlüsselt. Beim Lesen erfolgt keine automatische Umschlüsselung.

Ein Wechsel von `AUTH_KEY`/`AUTH_SALT` macht damit verschlüsselte Listen unlesbar.
Entfernen oder ändern Sie die Appointment-Konstante, betrifft dies Listen, die
mit dieser Konstante gespeichert wurden. Vor einem Schlüsselwechsel sind daher
die bisherigen Schlüssel zu sichern und die Listen kontrolliert zu übertragen.
Diese Version bietet keine automatische Schlüsselrotation an.

## Fehler und Datenhaltung

Fehlendes Sodium, unpassende Schlüssel oder beschädigte gespeicherte Daten
öffnen keinen eingeschränkten Raum: Neue Selbstbuchungen werden abgewiesen.
Die Verwaltung zeigt einen Fehler und überschreibt die betroffene Liste nicht
mit einem leeren Formular. Stellen Sie die ursprüngliche Konfiguration wieder
her, um die Liste erneut lesen und bearbeiten zu können.

Pro Raum liegt nur die verschlüsselte Liste in einem geschützten Post-Meta.
Der zufällige Website-Kontext liegt in einer eigenen, nicht automatisch geladenen
Option. Entschlüsselte Listen werden nicht in Transients, Logs, öffentlichen
REST-Antworten oder persistenten Caches gespeichert. Beim endgültigen Löschen
eines Raums entfernt WordPress dessen Post-Meta mit.

Das bestehende Verschlüsselungsverfahren für Buchungsdaten bleibt unverändert;
es findet keine Migration vorhandener Buchungen statt. Abteilungen, Lehrstühle,
Kategorien und Tags sind nicht Bestandteil dieser Version.

## Entwicklung und Tests

`composer test` prüft Speicherung, Schlüsselwechsel, Administration und die
öffentlichen Buchungswege zusammen mit den bestehenden PHP-Regressionstests.
`npm test` führt die JavaScript-Tests aus.

Der zusätzliche Test gegen eine lokale WordPress-Datenbank wird ausdrücklich mit
`wp --skip-plugins --skip-themes --url=SITE eval-file tests/room-access-integration.php`
gestartet. Er legt temporäre Räume, einen Platz und eine Buchung an, versendet
keine E-Mails und entfernt seine Testdaten im `finally`-Block. Die vorherigen
Website-Einstellungen werden dabei wiederhergestellt. Verwenden Sie dafür eine
lokale Testinstallation.
