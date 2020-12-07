# RRZE RSVP

Platzbuchungssystem der FAU.
Das Plugin ermöglicht das Reservieren, Einbuchen und Ausbuchen von Plätzen in Räumen oder anderen Orten.

## Download 

GitHub-Repo: https://github.com/RRZE-Webteam/rrze-rsvp


## Autor 
RRZE-Webteam , http://www.rrze.fau.de

## Copyright

GNU General Public License (GPL) Version 3 


## Zweck 

Mit Hilfe des (Sitz-)Platzbuchungs- und Reservierungsssystems (RSVP, „Réservez S’il Vous Plaît“) ist es Raumverantwortlichen möglich, 
auf ihren jeweiligen Webauftritten eine Buchungsmöglichkeit für Plätze in Seminarräumen, Hörsälen und anderen Räumen anzubieten. 
Personen, die einen Platz buchen wollen, können sich vor Ort zu einem Platz ein- und abbuchen. 
Zusätzlich soll auch optional eine Reservierungsmöglichkeit angeboten werden.

In Bezug auf die notwendigen Maßnahmen zur Eindämmung der Corona-Pandemie eignet sich das System auch für eine Kontaktverfolgung.

Das System eignet sich auch für die Verwaltung von Sprechstundenterminen.


## Dokumentation

Eine vollständige Dokumentation mit vielen Anwendungsbeispielen findet sich auf der Seite: 
https://www.wordpress.rrze.fau.de/plugins/fau-und-rrze-plugins/rsvp/


## Verwendung der SSO-Option (Raum Einstellungen)

Das Plugin unterstützt die Anmeldung für zentral-vergebene Kennungen von Studierenden und Beschäftigten der Universität Erlangen-Nürnberg. Mit dieser Option ist es möglich, den Zugriff auf die Reservierungsseite nur für Personen zu autorisieren, die eine IdM-Kennung haben.

Damit die SSO-Option funktioniert, muss zuerst das FAU-WebSSO-Plugin installiert und aktiviert werden.
Vgl. https://github.com/RRZE-Webteam/fau-websso

Folgen Sie dann den Anweisungen unter folgendem Link:
https://github.com/RRZE-Webteam/fau-websso/blob/master/README.md

Nachdem Sie den korrekten Betrieb des FAU-WebSSO-Plugins überprüft haben, können Sie die SSO-Option des RSVP-Plugins verwenden.


## Verwendung der LDAP-Option (Raum Einstellungen)

Das Plugin unterstützt auch die Anmeldung über LDAP. Mit dieser Option ist es möglich, den Zugriff auf die Reservierungsseite nur für Personen zu autorisieren, die einen Zugang zu Ihrem Active Directory haben.


## Kontaktverfolgung

Das Plugin bietet auch die Möglichkeit, alle Personen zu ermitteln, die sich mit einer gesuchten Person zeitgleich in denselben Räumen befanden.
Das Suchformular hierfür wird über "Werkzeuge" -> "RSVP Kontaktverfolgung" aufgerufen.
Falls der Zugriff nur für SuperAdmins möglich sein soll, muss das Plugin rrze-rsvp-network ( https://github.com/RRZE-Webteam/rrze-rsvp-network ) installiert und aktiviert werden.
Damit kann das Suchformular nur im Dashboard der Netzwerkverwaltung über "RSVP Kontaktverfolgung" aufgerufen werden und Administratoren erhalten einen Hinweis anstelle des Formulars. 
Alle persönlichen Daten der Kontaktverfolgung werden verschlüsselt gespeichert und automatisch nach 4 Wochen gelöscht. Nur im Fall eines Suchtreffers werden sie entschlüsselt und in einem CSV bereitgestellt.
