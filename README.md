# AHX WP Wordle

Ein einfaches Wordle-Spiel als WordPress-Plugin.

## Installation

1. Plugin-Ordner `ahx_wp_wordle` in `wp-content/plugins` ablegen.
2. Plugin **AHX WP Wordle** in WordPress aktivieren.

## Nutzung

Füge den Shortcode in eine Seite oder einen Beitrag ein:

`[ahx_wordle]`

Optional mit Sprache:

`[ahx_wordle lang="de_DE"]`

Im Frontend kann die Sprache zusätzlich über ein Dropdown gewechselt werden.
Status- und Hinweistexte im Spiel werden abhängig von der gewählten Sprache ausgegeben.

### Frontend-Tags (Shortcodes)

- Spiel: `[ahx_wordle_game]`
- Statistik: `[ahx_wordle_stats]`
- Anleitung: `[ahx_wordle_help]`

Der bisherige kombinierte Shortcode `[ahx_wordle]` bleibt weiterhin verfügbar.

## Admin-Bereich

Nach der Aktivierung findest du links im WordPress-Backend den Menüpunkt **AHX WP Wordle**.

Dort kannst du konfigurieren:

- Anzahl der Versuche (4 bis 10)
- Standard-Sprache (z. B. `de_DE`, `en_US`)
- Persistenzmodus (`auto`, `server`, `local_storage`)
- Sprachverwaltung mit **Hinzufügen/Löschen**

## Sprachverwaltung

- Neue Sprachen werden per Eingabefeld + Button hinzugefügt.
- Vorhandene Sprachen können über den jeweiligen Löschen-Button entfernt werden.
- Löschen ist blockiert, wenn in der Sprache Wörter oder Statistikdaten vorhanden sind.
- Die letzte verbleibende Sprache kann nicht gelöscht werden.

## Wörter in Datenbank

- Wörter werden in einer Datenbanktabelle pro Sprache gespeichert.
- Standardmäßig wird beim Aktivieren ein deutscher Grundwortschatz (`de_DE`) angelegt.
- Der alte Options-Wortbestand wird einmalig nach `de_DE` migriert.

## CSV-Import

- Import im Admin-Bereich über **AHX WP Wordle → Einstellungen → CSV-Import Wörter**.
- Pro Import wird die Zielsprache über ein Select aus den möglichen Sprachen gewählt.
- Pro Zeile wird das erste Feld gelesen (`;` und `,` als Trennzeichen werden akzeptiert).
- Nur gültige 5-Buchstaben-Wörter (`a-z`) werden übernommen.
- Dubletten pro Sprache werden erkannt und nicht erneut importiert.

## Tageswort-Logik

- Das Tageswort wird je Sprache in einer Verlaufstabelle gespeichert.
- Pro Sprache gibt es pro Tag genau ein eigenes Rätsel (separater Tagesstand je Sprache).
- Bereits verwendete Wörter werden für die letzten 365 Rätsel nicht erneut angeboten.
- Sind weniger als 365 Wörter in einer Sprache vorhanden, werden zunächst alle einmalig durchlaufen, bevor der Zyklus neu startet.

## Frontend-Statistik

Die Statistik wird pro aktuell gewählter Sprache angezeigt und umfasst:

- Gespielte Spiele
- Längste Siegesfolge
- Aktuelle Siegesfolge
- Gewinnrate

Zusätzlich gibt es eine Versuchsübersicht mit:

- Anzahl der Versuche
- Horizontalem Balken zur Visualisierung
- Absoluter Anzahl
- Relativer Anzahl

Unterhalb der Statistik läuft ein Live-Countdown bis zum nächsten Rätsel (Mitternacht in `Europe/Berlin`).

Zusätzlich gibt es im Statistikbereich einen Button **Statistik zurücksetzen**, der den Spielstand nur für die aktuell gewählte Sprache löscht.

Wenn ein angemeldeter Administrator im Frontend ein Wort eingibt, das nicht in der Liste enthalten ist, kann er dieses Wort direkt hinzufügen. Der aktuelle Versuch wird danach sofort als gültiger Rateversuch gewertet und das Wort ist anschließend für alle Benutzer nutzbar.

## Persistenz bei Refresh

- Nach jedem gültig gesendeten Versuch wird der Spielstand gespeichert.
- Hybrid-Modus (automatisch):
	- Eingeloggte Benutzer: Speicherung serverseitig in Benutzeroptionen (User Meta).
	- Gäste: Speicherung primär im `localStorage` des Browsers.
	- Fallback für Gäste bei deaktiviertem `localStorage`: serverseitiger AJAX-Flow mit Cookie-Speicherung.
- Über die Einstellung **Persistenzmodus** kann das Verhalten explizit erzwungen werden.
