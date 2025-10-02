# SumUp Terminal Web Checkout

Dieses Projekt stellt eine einfache PHP-Weboberfläche bereit, mit der du den Rechnungsbetrag direkt an ein SumUp-Terminal im Netzwerk senden kannst. Damit entfällt die manuelle Eingabe am Gerät.

## Voraussetzungen

- PHP 8.1 oder neuer mit aktivierter cURL-Erweiterung
- PHP-Erweiterung [libsodium](https://www.php.net/manual/de/book.sodium.php) für die verschlüsselte Schlüsselablage
- Ein SumUp-Terminal mit WLAN-Verbindung
- Ein SumUp **Terminal API** API-Key **oder** OAuth-Access-Token
- Die Seriennummer(n) der Terminals, die Zahlungen entgegennehmen sollen

## Konfiguration

1. Kopiere die Datei `config/config.example.php` nach `config/config.php`.
2. Lege fest, ob du dich mit einem API-Key (`auth_method = "api_key"`) oder einem OAuth-Token (`auth_method = "oauth"`) authentifizieren möchtest. Wenn du den API-Key nicht direkt in der Konfigurationsdatei speichern willst, lasse das Feld `api_key` leer und hinterlege den Schlüssel später sicher über `public/anmeldung.php`.
3. Trage deine SumUp-Terminals unter `sumup.terminals` ein. Erlaubte Formate:
   - Einzelne Seriennummer als String, z. B. `'ABCDEF123456'`.
   - Numerisches Array von Objekten mit `serial` und optional `label` (wie im Beispiel).
   - Assoziatives Array mit der Seriennummer als Schlüssel und einer Kurzbeschreibung als Wert.
   - Assoziatives Array mit der Seriennummer als Schlüssel und einem Array mit zusätzlichen Angaben, etwa `['label' => 'Tresen']`.
   Leerzeichen werden automatisch entfernt; fehlende Labels werden durch die Seriennummer ersetzt.
   Sollte das Dashboard weiterhin keine Auswahl anbieten, prüfe die gelben Konfigurationshinweise oberhalb des Formulars – sie nennen die betroffenen Einträge in `config/config.php`.
   Alternativ kannst du die Seriennummern direkt aus SumUp abrufen (siehe Abschnitt „Terminals automatisch abrufen“).
4. Passe optional die Standardwährung an (ISO-4217-Code, z. B. `EUR`).
5. Ersetze die Beispiel-Zugangsdaten durch eigene Benutzer und `password_hash`-Werte.
6. Lege bei Bedarf einen alternativen Speicherort für das Transaktionsprotokoll fest.
7. (Optional) Passe die Pfade des verschlüsselten Credential-Stores (`secure_store`) an, falls du die Dateien an einem anderen Ort ablegen möchtest.

> Tipp: Den API-Key findest du unter <https://me.sumup.com/developers>. Melde dich dort mit deinem Händlerkonto an, wähle **„Personal Access Tokens“** aus, erstelle bei Bedarf einen neuen Token und kopiere den angezeigten Schlüssel in die Konfiguration. Für Terminal-Aufrufe benötigst du den **Secret Key** mit dem Präfix `sum_sk_…`. Der veröffentlichbare Schlüssel `sum_pk_…` reicht nicht aus. Das OAuth-Access-Token erzeugst du im gleichen Bereich über deinen OAuth-Client.

## API-Key sicher speichern

Anstatt den SumUp-API-Key in `config/config.php` abzulegen, kannst du ihn nach der Einrichtung über `http://localhost:8000/anmeldung.php` sicher hinterlegen:

1. Melde dich mit den in der Konfiguration hinterlegten Zugangsdaten (HTTP Basic Auth) an.
2. Trage optional eine Referenz (z. B. deine Händler-E-Mail) ein und füge den SumUp-API-Key ein.
3. Nach dem Speichern verschlüsselt die Anwendung den Schlüssel mit der PHP-Erweiterung **libsodium** (`sodium`) und legt ihn in `var/sumup_credentials.json` ab; der dazugehörige Schlüssel liegt in `var/secure_store.key`.
4. Kehre zur Kasse (`index.php`) zurück. Die Anwendung lädt den Schlüssel automatisch und zeigt an, wann er zuletzt aktualisiert wurde.

Zum Löschen des gespeicherten API-Keys nutze die entsprechende Schaltfläche in `anmeldung.php`. Achte darauf, dass dein Webserver Schreibrechte auf das `var/`-Verzeichnis besitzt.

## Terminals automatisch abrufen

Sobald ein gültiger API-Key oder ein OAuth-Access-Token hinterlegt ist, kannst du in der Kassenoberfläche auf **„Terminals aus SumUp laden“** klicken. Die Anwendung ruft dann den Endpoint [`GET /v0.1/me/terminals`](https://developer.sumup.com/terminal-api) auf und listet alle dem Händlerkonto zugeordneten Geräte inklusive Seriennummer, optionalem Label, Modell und Status auf.

Nutze diese Übersicht, um neue Geräte schnell in `config/config.php` einzutragen oder um zu prüfen, ob ein Terminal für Cloud-Transaktionen freigeschaltet ist. Schlägt der Abruf fehl, zeigt die Oberfläche den HTTP-Status, die komplette API-Antwort und Hinweise zur Fehlerbehebung an (z. B. fehlende Berechtigungen oder inaktive Terminals).

## Authentifizierung bei SumUp

SumUp unterstützt für den Terminal-API-Zugriff zwei Verfahren:

- **API-Key („Personal Access Token“)** – geeignet, wenn du ausschließlich für dein eigenes Händlerkonto arbeitest. Wähle in der Konfiguration `auth_method = "api_key"` und trage den API-Key ein.
  Verwende dafür den Secret Key (`sum_sk_…`). Der Publishable Key (`sum_pk_…`) kann nur in Client-Anwendungen genutzt werden und wird von der Terminal-API abgelehnt.
- **OAuth 2.0 Access Token** – erforderlich, wenn deine Anwendung im Namen anderer Händler agiert oder du eine Plattform betreibst. Setze `auth_method = "oauth"` und hinterlege das Access Token.

### Erforderliche OAuth-Berechtigungen

Falls du OAuth verwendest, muss dein Client mindestens den Scope `transactions.terminal` erhalten. Ohne diese Berechtigung schlägt das Pushen des Betrags auf das Gerät mit einem HTTP-Fehler 403 fehl.

### Ablauf der Transaktion

Die Weboberfläche stößt serverseitig einen Request an den SumUp-Readers/Cloud-Endpunkt an, der das SumUp-Solo-Terminal anweist, den Betrag anzuzeigen. Der endgültige Status (bezahlt, abgebrochen usw.) wird von SumUp asynchron über Webhooks gemeldet – richte daher im SumUp-Dashboard die passenden Webhook-URLs ein, wenn du Transaktionen automatisiert weiterverarbeiten möchtest.

### Netzwerkanforderungen des Terminals

Für das Versenden einer Zahlungsanforderung muss dein SumUp-Terminal lediglich eine stabile Internetverbindung besitzen – die WLAN-Verbindung (oder optional Ethernet) zum SumUp-Backend reicht aus. Deine PHP-Anwendung kommuniziert ausschließlich mit der SumUp-Cloud; es ist nicht erforderlich, dass Terminal und Webserver im selben Netzwerk liegen oder dass zusätzliche Ports freigeschaltet werden. Wichtig ist nur, dass das Terminal online ist und im SumUp-Dashboard als erreichbar erscheint.

### Gibt es Alternativen zu OAuth?

Ja: Wenn du nur für dein eigenes Händlerkonto arbeitest, kannst du statt OAuth den SumUp-API-Key verwenden. Für Plattform-Szenarien mit mehreren Händlerkonten bleibt OAuth 2.0 die einzige Option.

### Warum helfen Webhooks nicht weiter?

Webhooks sind bei SumUp ausschließlich dafür gedacht, dich **über bereits stattgefundene Ereignisse** (z. B. abgeschlossene Zahlungen) zu informieren. Sie können **keine** Zahlungsanforderung an ein Terminal auslösen oder Beträge an das Gerät „pushen“. Um einen Betrag proaktiv auf ein Terminal zu schicken, benötigst du zwingend einen authentifizierten Aufruf der Terminal-API mit deinem API-Key oder einem OAuth-Access-Token.

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
- Bei HTTP-404-Antworten: Kontrolliere, ob die Seriennummer exakt mit der Anzeige im Händler-Dashboard übereinstimmt und ob das Terminal dem Konto zugeordnet sowie für Cloud-Transaktionen freigeschaltet ist.
- Erscheint nach dem Absenden lediglich eine weiße Seite, fehlt meist die PHP-Extension `curl`. Installiere sie auf deinem Server (unter Debian/Ubuntu z. B. `sudo apt install php-curl`) und starte PHP danach neu.

## Sicherheitshinweise

- Bewahre dein Access Token sicher auf und speichere es nicht im Quelltext oder in der Versionsverwaltung.
- Lege die Datei `config/config.php` niemals in Git ab (siehe `.gitignore`).
- Nutze HTTPS, wenn du die Anwendung produktiv einsetzt.
- Aktualisiere die Passworthashes regelmäßig und verwende für jeden Nutzer ein individuelles, starkes Kennwort.
