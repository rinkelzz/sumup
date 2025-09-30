# SumUp Terminal Web Checkout

Dieses Projekt stellt eine einfache PHP-Weboberfläche bereit, mit der du den Rechnungsbetrag direkt an ein SumUp-Terminal im Netzwerk senden kannst. Damit entfällt die manuelle Eingabe am Gerät.

## Voraussetzungen

- PHP 8.1 oder neuer mit aktivierter cURL-Erweiterung
- Ein SumUp-Terminal mit WLAN-Verbindung
- Ein SumUp **Terminal API** Zugangstoken (OAuth Access Token)
- Die Seriennummer(n) der Terminals, die Zahlungen entgegennehmen sollen

## Konfiguration

1. Kopiere die Datei `config/config.example.php` nach `config/config.php`.
2. Trage dein SumUp-Zugangstoken ein und hinterlege unter `terminals` eine Liste der verwendeten Geräte (Seriennummer + Anzeigename für das Dropdown).
3. Passe optional die Standardwährung an (ISO-4217-Code, z. B. `EUR`).
4. Ersetze die Beispiel-Zugangsdaten durch eigene Benutzer und `password_hash`-Werte.
5. Lege bei Bedarf einen alternativen Speicherort für das Transaktionsprotokoll fest.

> Tipp: Das Access Token erhältst du über den OAuth-Client in deinem SumUp-Entwicklerkonto. Achte darauf, dass es die Berechtigung `transactions.terminal` besitzt.

## Erforderliche OAuth-Berechtigungen

Für die Kommunikation mit der SumUp Terminal API muss der OAuth-Client mindestens den Scope `transactions.terminal` erhalten. Ohne diese Berechtigung schlägt das Pushen des Betrags auf das Gerät mit einem HTTP-Fehler 403 fehl.

## Anwendung starten

Starte den integrierten PHP-Server beispielsweise so:

```bash
php -S 0.0.0.0:8000 -t public
```

Rufe anschließend im Browser `http://localhost:8000` auf. Der Browser fragt nach den konfigurierten Zugangsdaten (HTTP Basic Auth). Nach erfolgreichem Login gib den Rechnungsbetrag (und optional Trinkgeld sowie eine Beschreibung) ein und klicke auf **„An Terminal senden“**. Die Anwendung erstellt eine Zahlungsanforderung über die SumUp-Terminal-API. Auf dem Gerät erscheint der Betrag zur Bestätigung.

## Transaktionsprotokoll

Jeder Zahlungsversuch wird mit Zeitpunkt, angemeldetem Nutzer, ausgewähltem Terminal, Betrag und Erfolg/Misserfolg in der Datei `var/transactions.log` protokolliert (oder in dem Pfad, den du in `config/config.php` angibst). Stelle sicher, dass der Webserver Schreibrechte auf dieses Verzeichnis besitzt.

## Fehlerbehandlung

- Bei API-Fehlern wird der HTTP-Statuscode sowie die Antwort von SumUp im Bereich „Antwortdetails“ angezeigt.
- Prüfe bei Authentifizierungsfehlern dein Access Token und dessen Berechtigungen.
- Stelle sicher, dass die Terminal-Seriennummer exakt mit der in deinem SumUp-Dashboard übereinstimmt.

## Sicherheitshinweise

- Bewahre dein Access Token sicher auf und speichere es nicht im Quelltext oder in der Versionsverwaltung.
- Lege die Datei `config/config.php` niemals in Git ab (siehe `.gitignore`).
- Nutze HTTPS, wenn du die Anwendung produktiv einsetzt.
- Aktualisiere die Passworthashes regelmäßig und verwende für jeden Nutzer ein individuelles, starkes Kennwort.
