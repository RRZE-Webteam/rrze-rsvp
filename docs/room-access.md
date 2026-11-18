# Buchungsberechtigungen für Räume

## Einrichtung und Bedienung

Die Funktion ist standardmäßig ausgeschaltet und gilt jeweils für eine Website,
auch innerhalb einer Multisite-Installation.

1. Richten Sie SSO oder LDAP ein und aktivieren Sie die entsprechende Loginpflicht
   in den Einstellungen des Raums.
2. Aktivieren Sie unter **Einstellungen → RRZE RSVP → Allgemeine Einstellungen**
   die Option **Buchungsberechtigungen verwalten**.
3. Öffnen Sie den Reiter **Buchungsberechtigungen** und wählen Sie einen Raum.
   Fügen Sie im Mehrzeilenfeld eine erlaubte E-Mail-Adresse pro Zeile hinzu.
   Optional können Sie den neuen Einträgen eine gemeinsame Abteilung zuweisen.
4. Bestehende Einträge bearbeiten Sie in der Tabelle. Speichern Sie alle Änderungen
   gemeinsam mit **Buchungsberechtigungen speichern**.

Leerzeichen am Anfang und Ende werden entfernt, Groß-/Kleinschreibung wird
vereinheitlicht und doppelte Einträge werden zusammengeführt. Ungültige Adressen
verhindern das gesamte Speichern. Domains, Wildcards und Aliasregeln werden nicht
unterstützt. Eine Person kann unabhängig in mehreren Räumen eingetragen werden.
Beim erneuten Einfügen einer vorhandenen Adresse bleibt deren Abteilung erhalten.
Widersprüchliche Zuordnungen derselben Adresse verhindern das Speichern.

Eine leere Liste bedeutet: Der Raum bleibt nach seinen bisherigen Regeln buchbar.
Wenn Sie alle Einträge entfernen und speichern, heben Sie seine zusätzliche
Einschränkung ausdrücklich auf. Schalten Sie die gesamte Funktion aus, bleiben die
Listen und der Abteilungskatalog gespeichert, werden aber nicht mehr für neue
Buchungen verwendet. Das Zurücksetzen der
allgemeinen RSVP-Einstellungen schaltet die Funktion ebenfalls aus und erhält die
raumbezogenen Listen. Beim erneuten Aktivieren gelten sie wieder.

## Abteilungen verwalten

Die Ansicht **Abteilungen** im Reiter **Buchungsberechtigungen** enthält einen
gemeinsamen Katalog für diese Website. Administratoren können Abteilungen anlegen,
umbenennen, archivieren und reaktivieren. Eine Abteilung kann in mehreren Räumen
verwendet werden. Pro erlaubter Adresse und Raum ist höchstens eine Abteilung
möglich; **Keine Abteilung** bleibt zulässig und verhindert keine Buchung.

Namen dürfen höchstens 200 Zeichen enthalten und müssen auch gegenüber archivierten
Abteilungen eindeutig sein; Groß-/Kleinschreibung und überflüssige Leerzeichen
erzeugen keine verschiedenen Namen. Die dauerhafte interne ID bleibt bei einer
Umbenennung gleich.

Archivierte Abteilungen stehen für neue Zuordnungen nicht zur Auswahl. Bestehende
Zuordnungen bleiben wirksam und können entfernt oder ersetzt werden. Eine endgültige
Löschung von Abteilungen ist nicht vorgesehen. Die Verwaltung von Katalog und
Berechtigungen erfordert weiterhin die WordPress-Berechtigung `manage_options`.

## Abteilung bei einer Buchung

Neue öffentliche Raumbuchungen übernehmen die zugehörige Abteilungs-ID und den
damaligen Namen unmittelbar vor ihrer Veröffentlichung. Die Zuordnung wird anhand
der verifizierten SSO-/LDAP-Adresse ermittelt. Angaben aus dem öffentlichen
Buchungsformular können sie nicht beeinflussen.

Dieser historische Stand wird separat verschlüsselt an der Buchung gespeichert.
Eine spätere Umbenennung, Archivierung oder Änderung der Berechtigungsliste lässt
ihn unverändert. Das gilt auch beim Deaktivieren der Berechtigungsverwaltung und
bei späteren Bearbeitungen der Buchung. In den Backend-Details wird **Abteilung bei
Buchung** schreibgeschützt angezeigt, sofern die Person die Buchung bearbeiten darf.
Es gibt keine öffentliche Ausgabe und keinen Eintrag in Buchungsmails.

Bestehende Buchungen werden nicht nachträglich zugeordnet. Neue manuelle
Backend-Buchungen, Kursbuchungen und Buchungen ohne zugewiesene Abteilung erhalten
keine automatische Zuordnung. Schlägt das Speichern einer erforderlichen
Abteilungszuordnung fehl, wird die neue Buchung abgebrochen und keine Mail versandt.

Der CSV-Download folgt in einem eigenen Schritt. Die derzeitige automatische
Löschung von Raumbuchungen, deren Beginn mehr als vier Wochen zurückliegt, bleibt
unverändert. Die benötigte Aufbewahrungsdauer muss deshalb vor Einführung von
Semester- oder Jahresauswertungen geklärt werden. Gelöschte Buchungen lassen sich
aus dem Abteilungskatalog nicht rekonstruieren.

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
Für historische Buchungszuordnungen wird ein eigener Schlüsselzweck abgeleitet;
deren Verschlüsselung ist zusätzlich an die Buchungs-ID gebunden. Es besteht
keine Laufzeitabhängigkeit vom Appointment-Plugin.

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
Historische Buchungszuordnungen werden nicht nachträglich neu verschlüsselt; ihre
ursprüngliche Schlüsselquelle bleibt zum Lesen erforderlich.

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

Pro Raum liegt nur die verschlüsselte Liste einschließlich der personenbezogenen
Abteilungszuordnung in einem geschützten Post-Meta. Der Website-Katalog enthält
ausschließlich Abteilungsnamen, IDs und den Archivierungsstatus. Buchungen speichern
ihren historischen Stand ebenfalls in einem geschützten, verschlüsselten Post-Meta.
Der zufällige Website-Kontext liegt in einer eigenen, nicht automatisch geladenen
Option. Entschlüsselte Listen werden nicht in Transients, Logs, öffentlichen
REST-Antworten oder persistenten Caches gespeichert. Beim endgültigen Löschen
eines Raums entfernt WordPress dessen Post-Meta mit.

Das bestehende Verschlüsselungsverfahren für Namen und E-Mail-Adressen von
Buchungen bleibt unverändert. Alte reine E-Mail-Berechtigungslisten werden ohne
Abteilung gelesen und erst beim ausdrücklichen Speichern ins neue Format überführt.
Es findet keine Migration vorhandener Buchungen statt. Mehrere Kategorien oder
Tags pro Person sind nicht Bestandteil dieser Version.

## Entwicklung und Tests

`composer test` prüft Speicherung, Schlüsselwechsel, Administration, Abteilungen,
historische Buchungszuordnungen und die öffentlichen Buchungswege zusammen mit den
bestehenden PHP-Regressionstests.
`npm test` führt die JavaScript-Tests aus.

Der zusätzliche Test gegen eine lokale WordPress-Datenbank wird ausdrücklich mit
`wp --skip-plugins --skip-themes --url=SITE eval-file tests/room-access-integration.php`
gestartet. Er legt temporäre Räume, einen Platz und eine Buchung an, versendet
keine E-Mails und entfernt seine Testdaten im `finally`-Block. Die vorherigen
Website-Einstellungen werden dabei wiederhergestellt. Verwenden Sie dafür eine
lokale Testinstallation.

`wp --skip-plugins --skip-themes --url=SITE eval-file tests/department-integration.php`
prüft zusätzlich den Abteilungskatalog, konkurrierende Listenänderungen, verschlüsselte
Zuordnungen und das unveränderte Fortbestehen historischer Buchungsdaten nach
Umbenennung, Archivierung oder Deaktivierung. Auch dieser Test entfernt seine
temporären Daten und stellt Einstellungen sowie Katalog wieder her.
