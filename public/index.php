<?php

declare(strict_types=1);

use SumUp\BasicAuth;
use SumUp\CredentialStore;
use SumUp\SumUpTerminalClient;

require_once __DIR__ . '/../src/SumUpTerminalClient.php';
require_once __DIR__ . '/../src/BasicAuth.php';
require_once __DIR__ . '/../src/CredentialStore.php';

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
    <title>Fehler – SumUp Terminal</title>
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

if (!isset($config['sumup']) || !is_array($config['sumup'])) {
    renderFatalError('In der Konfigurationsdatei fehlt der Abschnitt "sumup". Bitte ergänzen Sie config/config.php.');
}

foreach (['auth', 'log', 'secure_store'] as $section) {
    if (isset($config[$section]) && !is_array($config[$section])) {
        renderFatalError(sprintf('Der Abschnitt "%s" muss ein Array sein. Bitte korrigieren Sie config/config.php.', $section));
    }
}

/**
 * @var array{
 *     sumup: array{
 *         auth_method?: string,
 *         access_token?: string,
 *         api_key?: string,
 *         currency?: string,
 *         terminal_serial?: string,
 *         terminal_label?: string,
 *         terminals?: array<int|string, array{serial?: string,label?: string}|string>
 *     },
 *     auth?: array{realm?: string, users?: array<string, string>},
 *     log?: array{transactions_file?: string},
 *     secure_store?: array{credential_file?: string, key_file?: string}
 * } $config
 */

$sumUpConfig = $config['sumup'] ?? [];
$authMethod = strtolower((string) ($sumUpConfig['auth_method'] ?? ''));
$apiKey = trim((string) ($sumUpConfig['api_key'] ?? ''));
$accessToken = trim((string) ($sumUpConfig['access_token'] ?? ''));

$secureStoreConfig = $config['secure_store'] ?? [];
$credentialStore = null;
$secureStoreError = null;
$storedCredentialDetails = null;

if (isset($secureStoreConfig['credential_file'], $secureStoreConfig['key_file'])) {
    try {
        $credentialStore = new CredentialStore(
            (string) $secureStoreConfig['credential_file'],
            (string) $secureStoreConfig['key_file']
        );
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

$credential = $authMethod === 'oauth' ? $accessToken : $apiKey;

if ($credentialStore instanceof CredentialStore) {
    $storedCredentialDetails = $credentialStore->getApiCredential();
}

if ($authMethod === 'api_key' && $apiKey === '' && $storedCredentialDetails !== null) {
    $apiKey = $storedCredentialDetails['api_key'];
    $credential = $apiKey;
}

if ($credential === '') {
    if ($authMethod === 'oauth') {
        renderFatalError('Kein OAuth Access Token konfiguriert. Bitte ergänzen Sie config/config.php.');
    }

    $hint = 'Kein SumUp API-Key konfiguriert. Bitte ergänzen Sie config/config.php.';

    if ($credentialStore instanceof CredentialStore) {
        $hint .= ' Alternativ können Sie Ihren Schlüssel über anmeldung.php sicher hinterlegen.';
    }

    renderFatalError($hint);
}
$defaultTerminalSerial = (string) ($sumUpConfig['terminal_serial'] ?? '');
$defaultTerminalLabel = (string) ($sumUpConfig['terminal_label'] ?? '');
$currency = (string) ($sumUpConfig['currency'] ?? 'EUR');

/** @var array<int, array{serial:string,label:string}> $terminalOptions */
$terminalOptions = [];
/** @var array<string, string> $terminalLookup */
$terminalLookup = [];
$terminalWarnings = [];

$terminalsConfig = $sumUpConfig['terminals'] ?? null;

if (is_string($terminalsConfig) || is_int($terminalsConfig) || is_float($terminalsConfig)) {
    $terminalsConfig = [$terminalsConfig];
}

if ($terminalsConfig !== null && !is_array($terminalsConfig)) {
    $terminalWarnings[] = 'Die Konfiguration "sumup.terminals" muss entweder ein Array oder eine einfache Seriennummer sein. Bitte prüfen Sie config/config.php.';
}

if (is_array($terminalsConfig)) {
    foreach ($terminalsConfig as $key => $terminalConfig) {
        $serial = '';
        $label = '';

        if (is_array($terminalConfig)) {
            $serial = trim((string) ($terminalConfig['serial'] ?? ''));
            $keyRepresentsSerial = is_string($key) || is_int($key) || is_float($key);

            if ($serial === '' && $keyRepresentsSerial) {
                $serialFromKey = trim((string) $key);

                if ($serialFromKey !== '') {
                    $serial = $serialFromKey;
                }
            }

            if (isset($terminalConfig['label'])) {
                $label = trim((string) $terminalConfig['label']);
            } elseif ($keyRepresentsSerial) {
                $labelFromKey = trim((string) $key);

                if ($labelFromKey !== '' && $labelFromKey !== $serial) {
                    $label = $labelFromKey;
                }
            }
        } elseif (is_string($terminalConfig) || is_int($terminalConfig) || is_float($terminalConfig)) {
            $serial = trim((string) $terminalConfig);
            $label = is_string($key) ? trim((string) $key) : '';
        }

        if ($serial === '') {
            $humanReadableKey = is_string($key)
                ? sprintf('Schlüssel "%s"', $key)
                : sprintf('Index %d', (int) $key);

            $terminalWarnings[] = sprintf(
                'Der Terminaleintrag unter %s enthält keine Seriennummer. Bitte ergänzen Sie sie in config/config.php.',
                $humanReadableKey
            );

            continue;
        }

        if ($label === '') {
            $label = $serial;
        }

        $terminalOptions[] = [
            'serial' => $serial,
            'label' => $label,
        ];
        $terminalLookup[$serial] = $label;
    }
}

if ($terminalOptions === [] && $defaultTerminalSerial !== '') {
    $label = $defaultTerminalLabel !== '' ? $defaultTerminalLabel : $defaultTerminalSerial;
    $terminalOptions[] = [
        'serial' => $defaultTerminalSerial,
        'label' => $label,
    ];
    $terminalLookup[$defaultTerminalSerial] = $label;
}

if ($terminalOptions === [] && $terminalsConfig === null && $defaultTerminalSerial === '') {
    $terminalWarnings[] = 'Es wurden noch keine Terminals konfiguriert. Öffnen Sie config/config.php und fügen Sie unter "sumup.terminals" mindestens ein Gerät hinzu (siehe config/config.example.php).';
}

$selectedTerminalSerial = '';
$selectedTerminalLabel = '';

if ($terminalOptions !== []) {
    $selectedTerminalSerial = $terminalOptions[0]['serial'];
    $selectedTerminalLabel = $terminalOptions[0]['label'];
}

$logConfig = $config['log'] ?? [];
$transactionsLogFile = (string) ($logConfig['transactions_file'] ?? (__DIR__ . '/../var/transactions.log'));

$authConfig = $config['auth'] ?? [];
$username = BasicAuth::enforce($authConfig);

$environmentErrors = [];

if (!extension_loaded('curl')) {
    $environmentErrors[] = 'Die PHP-Extension "curl" ist nicht installiert. Ohne sie können keine Zahlungsanforderungen an SumUp gesendet werden.';
}

$error = null;
$result = null;
$logError = null;

/**
 * @param string $logFile
 * @param string $username
 * @param float  $amount
 * @param bool   $success
 * @param string $terminalSerial
 * @param string $terminalLabel
 */
function writeTransactionLog(
    string $logFile,
    string $username,
    float $amount,
    bool $success,
    string $terminalSerial,
    string $terminalLabel = ''
): bool
{
    $directory = dirname($logFile);

    if ($directory !== '' && !is_dir($directory)) {
        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            return false;
        }
    }

    $terminalInfo = '-';

    if ($terminalSerial !== '') {
        $terminalInfo = $terminalSerial;

        if ($terminalLabel !== '' && $terminalLabel !== $terminalSerial) {
            $terminalInfo = sprintf('%s (%s)', $terminalLabel, $terminalSerial);
        }
    }

    $line = sprintf(
        "%s\t%s\t%s\t%s\t%s\n",
        (new DateTimeImmutable('now'))->format('c'),
        $username !== '' ? $username : '-',
        $terminalInfo,
        number_format($amount, 2, '.', ''),
        $success ? 'success' : 'failure'
    );

    return file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX) !== false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = isset($_POST['amount']) ? (float) str_replace(',', '.', (string) $_POST['amount']) : 0.0;
    $tipAmount = isset($_POST['tip_amount']) && $_POST['tip_amount'] !== ''
        ? (float) str_replace(',', '.', (string) $_POST['tip_amount'])
        : null;
    $description = isset($_POST['description']) ? trim((string) $_POST['description']) : null;
    $requestedTerminalSerial = isset($_POST['terminal_serial']) ? trim((string) $_POST['terminal_serial']) : '';
    $externalId = sprintf('web-%s', bin2hex(random_bytes(4)));

    $paymentSuccessful = false;

    if ($terminalOptions === []) {
        $error = 'Es ist kein SumUp-Terminal konfiguriert.';
    } elseif ($requestedTerminalSerial !== '') {
        if (array_key_exists($requestedTerminalSerial, $terminalLookup)) {
            $selectedTerminalSerial = $requestedTerminalSerial;
            $selectedTerminalLabel = $terminalLookup[$requestedTerminalSerial];
        } else {
            $error = 'Ausgewähltes Terminal ist ungültig oder nicht konfiguriert.';
        }
    } elseif (count($terminalOptions) > 1) {
        $error = 'Bitte wählen Sie ein Terminal aus.';
    }

    if ($error === null && $environmentErrors === []) {
        try {
            $client = new SumUpTerminalClient($credential, $selectedTerminalSerial, $authMethod);
            $response = $client->sendPayment($amount, $currency, $externalId, $description, $tipAmount);

            $debugDetails = [
                'http_status' => $response['status'],
                'response' => $response['body'],
            ];

            if (isset($response['request'])) {
                $debugDetails['request'] = $response['request'];
            }

            if (isset($response['response_raw'])) {
                $debugDetails['response_raw'] = $response['response_raw'];
            }

            $debugHints = [];

            if ($response['status'] >= 200 && $response['status'] < 300) {
                $paymentSuccessful = true;
                $terminalDisplayName = $selectedTerminalLabel !== '' ? $selectedTerminalLabel : $selectedTerminalSerial;
                $result = [
                    'title' => 'Zahlungsanforderung gesendet',
                    'message' => $terminalDisplayName !== ''
                        ? sprintf(
                            'Der Betrag wurde an das Terminal "%s" übertragen. Warten Sie auf die Bestätigung auf dem Gerät.',
                            $terminalDisplayName
                        )
                        : 'Der Betrag wurde an das Terminal übertragen. Warten Sie auf die Bestätigung auf dem Gerät.',
                    'details' => $debugDetails,
                    'hints' => $debugHints,
                ];
            } else {
                $error = sprintf(
                    'Fehler beim Senden der Zahlungsanforderung (HTTP %d).',
                    $response['status']
                );

                if ($response['status'] === 404) {
                    $debugHints[] = 'SumUp meldet "Not Found". Prüfen Sie, ob die eingetragene Terminal-Seriennummer exakt mit dem Aufkleber unter dem Gerät übereinstimmt.';
                    $debugHints[] = 'Stellen Sie sicher, dass das Terminal für Cloud-/Solo-Transaktionen freigeschaltet ist und mit demselben Händlerkonto verbunden wurde, das den API-Key erzeugt hat.';
                    $debugHints[] = 'Kontrollieren Sie, ob das Terminal zuletzt online war. Inaktive oder abgemeldete Geräte akzeptieren keine Zahlungsanforderungen.';
                }

                if ($response['status'] === 401 || $response['status'] === 403) {
                    $debugHints[] = 'Der SumUp-Server lehnt die Authentifizierung ab. Prüfen Sie API-Key bzw. OAuth-Token und deren Berechtigungen.';
                }

                if (!empty($debugHints)) {
                    $error .= ' Bitte beachten Sie die Hinweise zur Fehlersuche weiter unten.';
                }

                $result = [
                    'title' => 'Antwort des SumUp-Servers',
                    'details' => $debugDetails,
                    'hints' => $debugHints,
                ];
            }
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }

    if (!writeTransactionLog(
        $transactionsLogFile,
        $username,
        $amount,
        $paymentSuccessful,
        $selectedTerminalSerial,
        $selectedTerminalLabel
    )) {
        $logError = 'Transaktionsprotokoll konnte nicht geschrieben werden. Bitte überprüfen Sie die Schreibrechte.';
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>SumUp Terminal Zahlung</title>
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
            background: #f6f8fb;
        }

        .container {
            background: #fff;
            padding: 2rem;
            border-radius: 1rem;
            box-shadow: 0 1.5rem 3rem rgba(15, 23, 42, 0.15);
            max-width: 32rem;
            width: 90%;
        }

        h1 {
            margin-top: 0;
            margin-bottom: 1.5rem;
            font-size: 1.75rem;
            color: #111827;
        }

        form {
            display: grid;
            gap: 1rem;
        }

        label {
            display: flex;
            flex-direction: column;
            font-weight: 600;
            color: #374151;
        }

        input,
        select,
        textarea {
            margin-top: 0.35rem;
            padding: 0.75rem 1rem;
            border-radius: 0.75rem;
            border: 1px solid #d1d5db;
            font-size: 1rem;
        }

        textarea {
            min-height: 4rem;
            resize: vertical;
        }

        button {
            background: #2563eb;
            color: #fff;
            border: none;
            border-radius: 999px;
            padding: 0.85rem 1.5rem;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s ease-in-out;
        }

        button:hover,
        button:focus {
            background: #1d4ed8;
        }

        .alert {
            border-radius: 0.75rem;
            padding: 1rem 1.25rem;
            margin-bottom: 1rem;
        }

        .alert.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .alert.success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .alert.info {
            background: #bfdbfe;
            color: #1e3a8a;
            border: 1px solid #93c5fd;
        }

        .alert.warning {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }

        pre {
            background: #0f172a;
            color: #e2e8f0;
            padding: 1rem;
            border-radius: 0.75rem;
            overflow-x: auto;
            font-size: 0.85rem;
        }

        @media (prefers-color-scheme: dark) {
            body {
                background: #0f172a;
            }

            .container {
                background: #111827;
                color: #f9fafb;
                box-shadow: none;
                border: 1px solid #1f2937;
            }

            h1 {
                color: #f3f4f6;
            }

            label {
                color: #d1d5db;
            }

            input,
            select,
            textarea {
                background: #1f2937;
                color: #f9fafb;
                border: 1px solid #374151;
            }

            .alert.success {
                background: rgba(22, 101, 52, 0.2);
                border-color: rgba(22, 101, 52, 0.4);
            }

            .alert.info {
                background: rgba(30, 64, 175, 0.2);
                border-color: rgba(30, 64, 175, 0.4);
                color: #bfdbfe;
            }

            .alert.warning {
                background: rgba(217, 119, 6, 0.2);
                border-color: rgba(217, 119, 6, 0.4);
                color: #fef3c7;
            }

            .alert.error {
                background: rgba(153, 27, 27, 0.2);
                border-color: rgba(153, 27, 27, 0.4);
            }

            pre {
                background: #1e293b;
                color: #e2e8f0;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>SumUp Zahlung starten</h1>

        <?php if ($authMethod === 'api_key'): ?>
            <?php if ($secureStoreError !== null): ?>
                <div class="alert error">
                    <strong>Sichere Ablage deaktiviert:</strong>
                    <div><?= htmlspecialchars($secureStoreError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                </div>
            <?php elseif ($credentialStore instanceof CredentialStore && $storedCredentialDetails !== null): ?>
                <div class="alert info">
                    <strong>API-Key geladen</strong>
                    <p>
                        Der hinterlegte Schlüssel<?= $storedCredentialDetails['merchant_id'] !== ''
                            ? ' für ' . htmlspecialchars($storedCredentialDetails['merchant_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                            : '' ?> wurde automatisch verwendet.
                        <?php if (isset($storedCredentialDetails['updated_at']) && $storedCredentialDetails['updated_at'] !== ''): ?>
                            <br>
                            Zuletzt aktualisiert: <?= htmlspecialchars($storedCredentialDetails['updated_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        <?php endif; ?>
                    </p>
                </div>
            <?php elseif ($credentialStore instanceof CredentialStore): ?>
                <div class="alert info">
                    <strong>API-Key hinterlegen</strong>
                    <p>
                        Besuchen Sie <a href="anmeldung.php">anmeldung.php</a>, um Ihren SumUp API-Key sicher zu speichern.
                    </p>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        <?php foreach ($terminalWarnings as $terminalWarning): ?>
            <div class="alert warning">
                <strong>Konfiguration:</strong>
                <div><?= htmlspecialchars($terminalWarning, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            </div>
        <?php endforeach; ?>

        <?php foreach ($environmentErrors as $envError): ?>
            <div class="alert error">
                <strong>Systemvoraussetzung:</strong>
                <div><?= htmlspecialchars($envError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            </div>
        <?php endforeach; ?>

        <?php if ($error !== null): ?>
            <div class="alert error">
                <strong>Fehler:</strong>
                <div><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            </div>
        <?php elseif ($result !== null): ?>
            <div class="alert success">
                <strong><?= htmlspecialchars($result['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                <p><?= htmlspecialchars($result['message'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            </div>
        <?php endif; ?>

        <?php if ($logError !== null): ?>
            <div class="alert error">
                <strong>Protokollierung:</strong>
                <div><?= htmlspecialchars($logError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            </div>
        <?php endif; ?>

        <?php if ($result !== null && !empty($result['hints'])): ?>
            <div class="alert info">
                <strong>Hinweise zur Fehlersuche:</strong>
                <ul>
                    <?php foreach ($result['hints'] as $hint): ?>
                        <li><?= htmlspecialchars($hint, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post">
            <?php if ($terminalOptions === []): ?>
                <label>
                    Terminal
                    <select name="terminal_serial" disabled>
                        <option>Bitte konfigurieren Sie mindestens ein Terminal</option>
                    </select>
                </label>
            <?php else: ?>
                <label>
                    Terminal
                    <select name="terminal_serial">
                        <?php foreach ($terminalOptions as $option): ?>
                            <?php $serial = $option['serial']; ?>
                            <option value="<?= htmlspecialchars($serial, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"<?= $serial === $selectedTerminalSerial ? ' selected' : '' ?>>
                                <?= htmlspecialchars($option['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>

            <label>
                Rechnungsbetrag (<?= htmlspecialchars($currency, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>)
                <input
                    type="number"
                    name="amount"
                    min="0"
                    step="0.01"
                    placeholder="z. B. 19.99"
                    value="<?= isset($_POST['amount']) ? htmlspecialchars((string) $_POST['amount'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>"
                    required
                >
            </label>

            <label>
                Trinkgeld (optional)
                <input
                    type="number"
                    name="tip_amount"
                    min="0"
                    step="0.01"
                    placeholder="z. B. 2.00"
                    value="<?= isset($_POST['tip_amount']) ? htmlspecialchars((string) $_POST['tip_amount'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>"
                >
            </label>

            <label>
                Beschreibung (optional)
                <textarea name="description" placeholder="Referenz oder Notiz"><?= isset($_POST['description']) ? htmlspecialchars((string) $_POST['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?></textarea>
            </label>

            <button type="submit"<?= $terminalOptions === [] || $environmentErrors !== [] ? ' disabled' : '' ?>>An Terminal senden</button>
        </form>

        <?php if ($result !== null): ?>
            <details>
                <summary>Antwortdetails</summary>
                <pre><?= htmlspecialchars(json_encode($result['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre>
            </details>
        <?php endif; ?>

        <?php if ($accessToken === '' || $terminalOptions === []): ?>
            <p class="alert error">
                Bitte hinterlegen Sie den Zugriffstoken und mindestens ein Terminal in <code>config/config.php</code>.
            </p>
        <?php endif; ?>
    </div>
</body>
</html>
