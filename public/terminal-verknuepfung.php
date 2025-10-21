<?php

declare(strict_types=1);

use SumUp\BasicAuth;
use SumUp\CredentialStore;
use SumUp\SumUpTerminalClient;

require_once __DIR__ . '/../src/BasicAuth.php';
require_once __DIR__ . '/../src/CredentialStore.php';
require_once __DIR__ . '/../src/SumUpTerminalClient.php';
require_once __DIR__ . '/../src/polyfills.php';

function renderFatalError(string $message, int $statusCode = 500): void
{
    http_response_code($statusCode);
    header('Content-Type: text/html; charset=UTF-8');

    $safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    echo <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Fehler – SumUp Terminalverknüpfung</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            color-scheme: light dark;
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
        }

        body {
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: #0f172a;
            color: #f9fafb;
            padding: 2rem;
        }

        .card {
            background: #111827;
            border-radius: 1rem;
            padding: 2.5rem 2rem;
            max-width: 32rem;
            box-shadow: 0 1.5rem 3rem rgba(15, 23, 42, 0.35);
        }

        h1 {
            margin-top: 0;
            margin-bottom: 1rem;
            font-size: 1.75rem;
        }

        p {
            margin: 0;
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>Es ist ein Fehler aufgetreten</h1>
        <p>{$safeMessage}</p>
    </div>
</body>
</html>
HTML;

    exit;
}

$configPath = __DIR__ . '/../config/config.php';

if (!file_exists($configPath)) {
    renderFatalError('Konfigurationsdatei nicht gefunden. Bitte kopieren Sie config/config.example.php nach config/config.php.');
}

/**
 * @var mixed $config
 */
$config = require $configPath;

if (!is_array($config)) {
    renderFatalError('Die Konfigurationsdatei muss ein Array zurückgeben. Bitte prüfen Sie config/config.php.');
}

foreach (['auth', 'sumup', 'secure_store'] as $section) {
    if (isset($config[$section]) && !is_array($config[$section])) {
        renderFatalError(sprintf('Der Abschnitt "%s" muss ein Array sein. Bitte korrigieren Sie config/config.php.', $section));
    }
}

/**
 * @var array{
 *     sumup?: array{
 *         auth_method?: string,
 *         api_key?: string,
 *         access_token?: string,
 *         currency?: string,
 *         terminal_serial?: string,
 *         terminal_label?: string,
 *         terminals?: array<int|string, array{serial?: string,label?: string}|string>
 *     },
 *     auth?: array{realm?: string, users?: array<string, string>},
 *     secure_store?: array{credential_file?: string, key_file?: string}
 * } $config
 */

$authConfig = $config['auth'] ?? [];
$authenticatedUser = BasicAuth::enforce($authConfig);

$sumUpConfig = $config['sumup'] ?? [];
$secureStoreConfig = $config['secure_store'] ?? [];

$authMethod = strtolower((string) ($sumUpConfig['auth_method'] ?? ''));
$apiKey = trim((string) ($sumUpConfig['api_key'] ?? ''));
$accessToken = trim((string) ($sumUpConfig['access_token'] ?? ''));

$credentialStore = null;
$storedCredentialDetails = null;
$secureStoreError = null;

if (isset($secureStoreConfig['credential_file'], $secureStoreConfig['key_file'])) {
    try {
        $credentialStore = new CredentialStore(
            (string) $secureStoreConfig['credential_file'],
            (string) $secureStoreConfig['key_file']
        );
        $storedCredentialDetails = $credentialStore->getApiCredential();
    } catch (Throwable $storeException) {
        $secureStoreError = $storeException->getMessage();
    }
}

if ($authMethod === '') {
    $authMethod = $accessToken !== '' ? 'oauth' : 'api_key';
}

if (!in_array($authMethod, ['api_key', 'oauth'], true)) {
    renderFatalError('Ungültige SumUp-Authentifizierungsmethode. Erlaubt sind "api_key" oder "oauth".');
}

if ($authMethod === 'api_key' && $apiKey === '' && $storedCredentialDetails !== null) {
    $apiKey = $storedCredentialDetails['api_key'];
}

$credential = $authMethod === 'oauth' ? $accessToken : $apiKey;

$defaultTerminalSerial = (string) ($sumUpConfig['terminal_serial'] ?? '');
$defaultTerminalLabel = (string) ($sumUpConfig['terminal_label'] ?? '');
$terminalOptions = [];
$terminalWarnings = [];

$terminalsConfig = $sumUpConfig['terminals'] ?? null;

if ($terminalsConfig !== null && !is_array($terminalsConfig)) {
    $terminalWarnings[] = 'Die Konfiguration "sumup.terminals" muss ein Array sein. Bitte prüfen Sie config/config.php.';
}

if (is_array($terminalsConfig)) {
    foreach ($terminalsConfig as $key => $terminalConfig) {
        $serial = '';
        $label = '';

        if (is_array($terminalConfig)) {
            $serial = trim((string) ($terminalConfig['serial'] ?? ''));
            $label = isset($terminalConfig['label']) ? trim((string) $terminalConfig['label']) : '';
            if ($serial === '' && $key !== '' && !is_int($key)) {
                $serial = trim((string) $key);
            }
        } elseif (is_string($terminalConfig) || is_int($terminalConfig) || is_float($terminalConfig)) {
            $serial = trim((string) $terminalConfig);
            if (!is_int($key)) {
                $label = trim((string) $key);
            }
        } else {
            $terminalWarnings[] = sprintf(
                'Ungültiger Terminal-Eintrag für Schlüssel "%s". Erwartet werden Zeichenkette oder Array mit "serial".',
                is_scalar($key) ? (string) $key : '[nicht druckbarer Schlüssel]'
            );
        }

        if ($serial === '') {
            if (!is_int($key) && trim((string) $key) !== '') {
                $serial = trim((string) $key);
            } else {
                $terminalWarnings[] = sprintf(
                    'Ein Terminal-Eintrag (Schlüssel "%s") enthält keine Seriennummer.',
                    is_scalar($key) ? (string) $key : '[nicht druckbarer Schlüssel]'
                );
                continue;
            }
        }

        if ($label === '' || $label === $serial) {
            $label = $serial;
        }

        $terminalOptions[$serial] = $label;
    }
}

if ($terminalOptions === [] && $defaultTerminalSerial !== '') {
    $terminalOptions[$defaultTerminalSerial] = $defaultTerminalLabel !== '' ? $defaultTerminalLabel : $defaultTerminalSerial;
}

if ($terminalOptions === [] && $terminalsConfig === null && $defaultTerminalSerial === '') {
    $terminalWarnings[] = 'Es wurden noch keine Terminals konfiguriert. Öffnen Sie config/config.php und fügen Sie unter "sumup.terminals" mindestens ein Gerät hinzu (siehe config/config.example.php).';
}

$selectedTerminalSerial = array_key_first($terminalOptions) ?? '';
$selectedTerminalLabel = $selectedTerminalSerial !== '' ? $terminalOptions[$selectedTerminalSerial] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestedTerminalSerial = isset($_POST['terminal_serial']) ? trim((string) $_POST['terminal_serial']) : '';
    if ($requestedTerminalSerial !== '' && array_key_exists($requestedTerminalSerial, $terminalOptions)) {
        $selectedTerminalSerial = $requestedTerminalSerial;
        $selectedTerminalLabel = $terminalOptions[$requestedTerminalSerial];
    }
}

$activationCode = '';
$responseStatus = null;
$responseBody = null;
$errorMessage = null;
$successMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $activationCode = isset($_POST['activation_code']) ? trim((string) $_POST['activation_code']) : '';

    if ($credential === '') {
        $errorMessage = 'Es sind keine SumUp-Zugangsdaten hinterlegt. Bitte ergänzen Sie API-Key oder Access Token in config/config.php oder über anmeldung.php.';
    } elseif ($selectedTerminalSerial === '') {
        $errorMessage = 'Bitte wählen Sie ein Terminal aus der Konfiguration.';
    } elseif ($activationCode === '') {
        $errorMessage = 'Bitte geben Sie den Aktivierungscode ein, der auf dem Terminal angezeigt wird.';
    } else {
        try {
            $client = new SumUpTerminalClient($credential, $selectedTerminalSerial, $authMethod);
            $result = $client->activateTerminal($activationCode);
            $responseStatus = $result['status'];
            $responseBody = $result['body'];

            if ($responseStatus >= 200 && $responseStatus < 300) {
                $successMessage = sprintf(
                    'Terminal "%s" wurde erfolgreich aktiviert.',
                    $selectedTerminalLabel !== '' ? $selectedTerminalLabel : $selectedTerminalSerial
                );
            } else {
                $errorMessage = 'Der Aktivierungsversuch wurde von der SumUp-API zurückgewiesen. Bitte prüfen Sie die Antwortdetails unten.';
            }
        } catch (Throwable $exception) {
            $errorMessage = $exception->getMessage();
        }
    }
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function prettyPrintJson(mixed $data): string
{
    if ($data === null) {
        return '';
    }

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        return '';
    }

    return $json;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>SumUp-Terminal verknüpfen</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            color-scheme: light;
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            background-color: #f3f4f6;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: linear-gradient(160deg, #0f172a 0%, #1f2937 40%, #f3f4f6 40%, #f3f4f6 100%);
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 4rem 1.5rem;
        }

        .container {
            background: #ffffff;
            width: min(960px, 100%);
            border-radius: 1.5rem;
            box-shadow: 0 1.5rem 3rem rgba(15, 23, 42, 0.15);
            overflow: hidden;
        }

        header {
            background: #0f172a;
            color: #f9fafb;
            padding: 2.5rem 3rem;
        }

        header h1 {
            margin: 0;
            font-size: 2rem;
        }

        header p {
            margin-top: 0.75rem;
            color: rgba(248, 250, 252, 0.8);
            max-width: 48rem;
            line-height: 1.6;
        }

        main {
            padding: 2.5rem 3rem 3rem;
            display: grid;
            gap: 2rem;
        }

        .alert {
            padding: 1rem 1.25rem;
            border-radius: 0.75rem;
            font-weight: 500;
            line-height: 1.5;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .alert-success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .alert-warning {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }

        form {
            display: grid;
            gap: 1.5rem;
        }

        label {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            font-weight: 600;
            color: #111827;
        }

        select,
        input[type="text"] {
            border: 1px solid #d1d5db;
            border-radius: 0.75rem;
            padding: 0.85rem 1rem;
            font-size: 1rem;
            font-family: inherit;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        select:focus,
        input[type="text"]:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
        }

        button {
            justify-self: start;
            background: linear-gradient(120deg, #2563eb, #7c3aed);
            color: #ffffff;
            border: none;
            border-radius: 0.75rem;
            padding: 0.85rem 1.5rem;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.15s ease, opacity 0.2s ease;
        }

        button:hover {
            transform: translateY(-1px);
            box-shadow: 0 0.75rem 1.5rem rgba(37, 99, 235, 0.25);
        }

        button:disabled {
            cursor: not-allowed;
            opacity: 0.6;
            box-shadow: none;
        }

        .response-card {
            background: #f9fafb;
            border-radius: 1rem;
            border: 1px solid #e5e7eb;
            padding: 1.5rem;
        }

        .response-card h2 {
            margin-top: 0;
            color: #111827;
            font-size: 1.25rem;
        }

        pre {
            margin: 0;
            background: #111827;
            color: #e5e7eb;
            border-radius: 0.75rem;
            padding: 1rem;
            overflow: auto;
            font-size: 0.9rem;
        }

        .badge {
            display: inline-block;
            padding: 0.35rem 0.6rem;
            border-radius: 999px;
            font-size: 0.85rem;
            font-weight: 600;
            background: #e5e7eb;
            color: #374151;
        }

        .terminal-warnings {
            display: grid;
            gap: 0.75rem;
        }

        footer {
            padding: 0 3rem 2.5rem;
            color: #6b7280;
            font-size: 0.9rem;
        }

        @media (max-width: 720px) {
            body {
                padding: 3rem 1rem;
            }

            header,
            main,
            footer {
                padding-left: 1.75rem;
                padding-right: 1.75rem;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <header>
        <h1>SumUp-Terminal verknüpfen</h1>
        <p>Melden Sie sich beim gewünschten Gerät an, indem Sie den auf dem Terminal angezeigten Aktivierungscode eingeben. Die Anwendung übermittelt den Code direkt an die SumUp-Terminal-API.</p>
    </header>
    <main>
        <?php if ($terminalWarnings !== []): ?>
            <div class="terminal-warnings">
                <?php foreach ($terminalWarnings as $warning): ?>
                    <div class="alert alert-warning"><?= escape($warning) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($secureStoreError !== null): ?>
            <div class="alert alert-warning">Sichere Ablage konnte nicht geladen werden: <?= escape($secureStoreError) ?></div>
        <?php endif; ?>

        <?php if ($errorMessage !== null): ?>
            <div class="alert alert-error"><?= escape($errorMessage) ?></div>
        <?php elseif ($successMessage !== null): ?>
            <div class="alert alert-success"><?= escape($successMessage) ?></div>
        <?php endif; ?>

        <form method="post">
            <label>
                Terminal auswählen
                <select name="terminal_serial"<?= $terminalOptions === [] ? ' disabled' : '' ?>>
                    <?php if ($terminalOptions === []): ?>
                        <option value="">Keine Terminals konfiguriert</option>
                    <?php else: ?>
                        <?php foreach ($terminalOptions as $serial => $label): ?>
                            <option value="<?= escape($serial) ?>"<?= $serial === $selectedTerminalSerial ? ' selected' : '' ?>><?= escape($label) ?></option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </label>

            <label>
                Aktivierungscode
                <input type="text" name="activation_code" value="<?= escape($activationCode) ?>" placeholder="z. B. 123-456" autocomplete="one-time-code"<?= $terminalOptions === [] ? ' disabled' : '' ?>>
            </label>

            <button type="submit"<?= $terminalOptions === [] || $credential === '' ? ' disabled' : '' ?>>Terminal verknüpfen</button>
        </form>

        <div class="response-card">
            <h2>Antwortdetails</h2>
            <p>Angemeldeter Benutzer: <span class="badge"><?= escape($authenticatedUser) ?></span></p>
            <p>Verwendete Authentifizierung: <span class="badge"><?= escape(strtoupper($authMethod)) ?></span></p>
            <p>Ausgewähltes Terminal: <span class="badge"><?= escape($selectedTerminalLabel !== '' ? $selectedTerminalLabel : ($selectedTerminalSerial !== '' ? $selectedTerminalSerial : '-')) ?></span></p>
            <p>Statuscode: <span class="badge"><?= $responseStatus !== null ? escape((string) $responseStatus) : '—' ?></span></p>
            <?php if ($responseBody !== null): ?>
                <pre><?= escape(prettyPrintJson($responseBody)) ?></pre>
            <?php else: ?>
                <pre>—</pre>
            <?php endif; ?>
        </div>
    </main>
    <footer>
        Tipp: Bei neuen Terminals zeigt das Gerät einen sechsstelligen Aktivierungscode an. Tragen Sie diesen hier ein, um das Gerät Ihrem SumUp-Account zuzuordnen. Bei Problemen prüfen Sie, ob der API-Key/OAuth-Token korrekt ist und ob das Terminal bereits einem anderen Konto zugewiesen wurde.
    </footer>
</div>
</body>
</html>
