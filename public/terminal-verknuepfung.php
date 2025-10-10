<?php

declare(strict_types=1);

use SumUp\BasicAuth;
use SumUp\CredentialStore;
use SumUp\SumUpTerminalClient;

require_once __DIR__ . '/../src/BasicAuth.php';
require_once __DIR__ . '/../src/CredentialStore.php';
require_once __DIR__ . '/../src/SumUpTerminalClient.php';

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
    <title>Fehler – Terminal koppeln</title>
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
 *     auth?: array{realm?: string, users?: array<string, string>},
 *     secure_store?: array{credential_file?: string, key_file?: string},
 *     sumup?: array{
 *         auth_method?: string,
 *         api_key?: string,
 *         access_token?: string,
 *         merchant_code?: string,
 *         merchant_id?: string
 *     }
 * } $config
 */

$authConfig = $config['auth'] ?? [];
$authenticatedUser = BasicAuth::enforce($authConfig);

$sumUpConfig = $config['sumup'] ?? [];
$authMethod = strtolower((string) ($sumUpConfig['auth_method'] ?? ''));
$apiKey = trim((string) ($sumUpConfig['api_key'] ?? ''));
$accessToken = trim((string) ($sumUpConfig['access_token'] ?? ''));
$merchantCodeFromConfig = '';

if (isset($sumUpConfig['merchant_code'])) {
    $merchantCodeFromConfig = (string) $sumUpConfig['merchant_code'];
} elseif (isset($sumUpConfig['merchant_id'])) {
    $merchantCodeFromConfig = (string) $sumUpConfig['merchant_id'];
}

if ($authMethod === '') {
    $authMethod = $accessToken !== '' ? 'oauth' : 'api_key';
}

if (!in_array($authMethod, ['api_key', 'oauth'], true)) {
    renderFatalError('Ungültige SumUp-Authentifizierungsmethode. Erlaubt sind "api_key" oder "oauth".');
}

$secureStoreConfig = $config['secure_store'] ?? [];
$credentialStore = null;
$storedCredential = null;
$storeError = null;

if (isset($secureStoreConfig['credential_file'], $secureStoreConfig['key_file'])) {
    try {
        $credentialStore = new CredentialStore(
            (string) $secureStoreConfig['credential_file'],
            (string) $secureStoreConfig['key_file']
        );
        $storedCredential = $credentialStore->getApiCredential();
    } catch (Throwable $exception) {
        $storeError = $exception->getMessage();
    }
}

$credentialOptions = [];

if ($authMethod === 'api_key') {
    if ($storedCredential !== null && isset($storedCredential['api_key'])) {
        $credentialOptions['store'] = [
            'label' => 'Gespeicherter API-Key',
            'credential' => (string) $storedCredential['api_key'],
            'merchant' => isset($storedCredential['merchant_id']) ? (string) $storedCredential['merchant_id'] : '',
        ];
    }

    if ($apiKey !== '') {
        $credentialOptions['config'] = [
            'label' => 'API-Key aus config.php',
            'credential' => $apiKey,
            'merchant' => $merchantCodeFromConfig,
        ];
    }
} else {
    if ($accessToken !== '') {
        $credentialOptions['config'] = [
            'label' => 'OAuth-Access-Token aus config.php',
            'credential' => $accessToken,
            'merchant' => $merchantCodeFromConfig,
        ];
    }
}

$defaultCredentialKey = array_key_first($credentialOptions) ?: null;
$selectedCredentialKey = $defaultCredentialKey;
$activationResponse = null;
$activationError = null;
$activationHints = [];
$successMessage = null;

$submittedActivationCode = '';
$submittedLabel = '';
$submittedMerchantCode = $merchantCodeFromConfig;
$curlExampleActivationCode = 'AB12CD';
$curlExampleLabelLine = '';

if ($storedCredential !== null && isset($storedCredential['merchant_id']) && $storedCredential['merchant_id'] !== '') {
    $submittedMerchantCode = (string) $storedCredential['merchant_id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedActivationCode = isset($_POST['activation_code']) ? trim((string) $_POST['activation_code']) : '';
    $submittedLabel = isset($_POST['label']) ? trim((string) $_POST['label']) : '';
    $submittedMerchantCode = isset($_POST['merchant_code']) ? trim((string) $_POST['merchant_code']) : $submittedMerchantCode;
    $selectedCredentialKey = isset($_POST['credential_source']) ? (string) $_POST['credential_source'] : $selectedCredentialKey;

    if ($submittedActivationCode === '') {
        $activationError = 'Bitte geben Sie den Aktivierungscode vom Terminal ein.';
    } elseif ($selectedCredentialKey === null || !isset($credentialOptions[$selectedCredentialKey])) {
        $activationError = 'Es steht kein gültiges Zugangsdaten-Set zur Verfügung. Prüfen Sie config.php oder speichern Sie einen API-Key.';
    } else {
        $selectedCredential = $credentialOptions[$selectedCredentialKey]['credential'];
        $merchantForRequest = $submittedMerchantCode !== '' ? $submittedMerchantCode : ($credentialOptions[$selectedCredentialKey]['merchant'] ?? '');

        try {
            $activationResponse = SumUpTerminalClient::activateTerminal(
                $selectedCredential,
                $authMethod,
                $submittedActivationCode,
                $merchantForRequest,
                $submittedLabel !== '' ? $submittedLabel : null
            );

            $status = $activationResponse['status'];

            if ($status >= 200 && $status < 300) {
                $successMessage = 'Terminal wurde erfolgreich mit der SumUp-Cloud verknüpft.';
                if ($merchantForRequest === '') {
                    $activationHints[] = 'Fügen Sie die neue Seriennummer anschließend in config/config.php unter sumup.terminals ein.';
                } else {
                    $activationHints[] = sprintf('Das Terminal ist jetzt dem Händler %s zugeordnet. Ergänzen Sie die Seriennummer in Ihrer Konfiguration.', $merchantForRequest);
                }
            } else {
                $activationError = sprintf('SumUp antwortete mit HTTP %d. Prüfen Sie Händlercode, Aktivierungscode und ob das Terminal bereits verknüpft ist.', $status);
            }
        } catch (Throwable $exception) {
            $activationError = $exception->getMessage();
        }
    }

    if ($activationError === null && $authMethod === 'api_key' && $submittedMerchantCode === '') {
        $activationHints[] = 'Für API-Key-Anfragen empfiehlt SumUp den Händlercode (Format MCRXXXX) anzugeben. Ohne diesen weicht die Anwendung auf generische Endpoints aus, die je nach Konto deaktiviert sind.';
    }
}

if ($submittedActivationCode !== '') {
    $curlExampleActivationCode = $submittedActivationCode;
}

if ($submittedLabel !== '') {
    $escapedLabel = addcslashes($submittedLabel, '\\"');
    $curlExampleLabelLine = ",\n    \\\"label\\\": \\\"" . $escapedLabel . "\\\"";
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redactCredentialPreview(string $credential): string
{
    $length = strlen($credential);

    if ($length <= 8) {
        return str_repeat('•', $length);
    }

    return substr($credential, 0, 4) . str_repeat('•', $length - 8) . substr($credential, -4);
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>SumUp Terminal koppeln</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            color-scheme: light dark;
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: linear-gradient(160deg, #0f172a 0%, #1f2937 40%, #111827 100%);
            color: #0f172a;
            display: flex;
            justify-content: center;
            padding: 2.5rem 1rem;
        }

        .container {
            width: min(960px, 100%);
            background: rgba(15, 23, 42, 0.85);
            backdrop-filter: blur(12px);
            border-radius: 1.5rem;
            padding: 2.5rem;
            box-shadow: 0 2rem 3.5rem rgba(15, 23, 42, 0.45);
            color: #f8fafc;
        }

        h1 {
            margin-top: 0;
            font-size: clamp(1.8rem, 2.5vw, 2.4rem);
            margin-bottom: 1.25rem;
        }

        p {
            margin-top: 0;
            line-height: 1.6;
        }

        a {
            color: #60a5fa;
        }

        .instructions {
            background: rgba(30, 64, 175, 0.2);
            border: 1px solid rgba(59, 130, 246, 0.35);
            border-radius: 1rem;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .instructions ol {
            margin: 0;
            padding-left: 1.5rem;
        }

        .instructions li {
            margin-bottom: 0.75rem;
        }

        form {
            display: grid;
            gap: 1.25rem;
        }

        fieldset {
            border: 1px solid rgba(148, 163, 184, 0.4);
            border-radius: 1rem;
            padding: 1.5rem;
        }

        legend {
            padding: 0 0.5rem;
            font-weight: 600;
        }

        label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.35rem;
        }

        input[type="text"],
        input[type="password"],
        textarea,
        select {
            width: 100%;
            padding: 0.75rem 1rem;
            border-radius: 0.75rem;
            border: 1px solid rgba(148, 163, 184, 0.35);
            background: rgba(15, 23, 42, 0.6);
            color: #f8fafc;
            font-size: 1rem;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        input:focus,
        textarea:focus,
        select:focus {
            outline: none;
            border-color: rgba(96, 165, 250, 0.8);
            box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.35);
        }

        .radio-group {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .radio-option {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            border-radius: 0.75rem;
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid transparent;
        }

        .radio-option input[type="radio"] {
            margin-top: 0.35rem;
        }

        .radio-option.selected {
            border-color: rgba(96, 165, 250, 0.75);
            box-shadow: 0 0 0 2px rgba(96, 165, 250, 0.35);
        }

        button[type="submit"] {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            border: none;
            border-radius: 999px;
            padding: 0.9rem 2.5rem;
            font-size: 1.05rem;
            font-weight: 600;
            color: #f8fafc;
            cursor: pointer;
            box-shadow: 0 1rem 2rem rgba(37, 99, 235, 0.35);
            transition: transform 0.15s ease, box-shadow 0.2s ease;
        }

        button[type="submit"]:hover {
            transform: translateY(-2px);
            box-shadow: 0 1.25rem 2.25rem rgba(37, 99, 235, 0.45);
        }

        button[type="submit"]:active {
            transform: translateY(0);
        }

        .alert {
            border-radius: 1rem;
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.5rem;
            border: 1px solid transparent;
        }

        .alert.error {
            background: rgba(153, 27, 27, 0.25);
            border-color: rgba(248, 113, 113, 0.35);
        }

        .alert.success {
            background: rgba(22, 101, 52, 0.25);
            border-color: rgba(74, 222, 128, 0.35);
        }

        .alert.info {
            background: rgba(30, 64, 175, 0.2);
            border-color: rgba(59, 130, 246, 0.35);
        }

        details {
            background: rgba(15, 23, 42, 0.6);
            border-radius: 1rem;
            padding: 1rem 1.25rem;
            border: 1px solid rgba(148, 163, 184, 0.35);
            margin-top: 1.5rem;
        }

        summary {
            cursor: pointer;
            font-weight: 600;
            margin-bottom: 0.75rem;
        }

        pre {
            margin: 0;
            white-space: pre-wrap;
            word-break: break-word;
            font-size: 0.9rem;
            background: rgba(15, 23, 42, 0.8);
            padding: 1rem;
            border-radius: 0.75rem;
        }

        .nav-links {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }

        .nav-links a {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.65rem 1rem;
            border-radius: 999px;
            border: 1px solid rgba(148, 163, 184, 0.35);
            text-decoration: none;
            font-weight: 600;
            color: #e2e8f0;
            transition: background 0.2s ease, border-color 0.2s ease;
        }

        .nav-links a:hover {
            background: rgba(96, 165, 250, 0.2);
            border-color: rgba(96, 165, 250, 0.45);
        }

        ul.hints {
            margin: 0;
            padding-left: 1.25rem;
        }

        ul.hints li {
            margin-bottom: 0.5rem;
        }

        @media (max-width: 720px) {
            body {
                padding: 1.5rem 0.75rem;
            }

            .container {
                padding: 1.75rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <nav class="nav-links">
            <a href="index.php">Zurück zur Kasse</a>
            <a href="anmeldung.php">API-Key hinterlegen</a>
        </nav>

        <h1>SumUp-Terminal mit der Cloud verbinden</h1>
        <p>Verknüpfe ein Solo- oder Air-Terminal mit deinem Händlerkonto, damit anschließend Zahlungsanforderungen über die API funktionieren.</p>

        <section class="instructions">
            <h2>Schritt-für-Schritt-Anleitung am Gerät</h2>
            <ol>
                <li>Öffne auf dem Terminal das Menü <strong>Einstellungen &gt; Kassensystem verbinden</strong> (oder ähnlicher Wortlaut) und melde dich ggf. vom Kassensystem ab.</li>
                <li>Wähle <strong>Mit API verbinden</strong>. Das Gerät zeigt einen einmaligen Aktivierungscode (meist 6–8 Zeichen) an.</li>
                <li>Trage den Code unten ein und sende ihn mit deinem SumUp-Zugang (API-Key oder OAuth-Token) an die Terminal-API.</li>
                <li>Nach wenigen Sekunden bestätigt das Terminal die Verbindung. Danach kannst du das Gerät in der Kasse auswählen.</li>
            </ol>
        </section>

        <?php if ($storeError !== null): ?>
            <div class="alert error">
                <strong>Sichere Ablage:</strong>
                <div><?= escape($storeError) ?></div>
            </div>
        <?php endif; ?>

        <?php if ($credentialOptions === []): ?>
            <div class="alert error">
                <strong>Zugangsdaten fehlen:</strong>
                <p>Hinterlege einen SumUp API-Key in <a href="config/config.php">config/config.php</a> oder speichere ihn verschlüsselt über <a href="anmeldung.php">anmeldung.php</a>. Für OAuth hinterlege ein gültiges Access Token.</p>
            </div>
        <?php endif; ?>

        <?php if ($activationError !== null): ?>
            <div class="alert error">
                <strong>Fehler:</strong>
                <div><?= escape($activationError) ?></div>
            </div>
        <?php elseif ($successMessage !== null): ?>
            <div class="alert success">
                <strong>Erfolg:</strong>
                <div><?= escape($successMessage) ?></div>
            </div>
        <?php endif; ?>

        <?php if ($activationHints !== []): ?>
            <div class="alert info">
                <strong>Hinweise:</strong>
                <ul class="hints">
                    <?php foreach ($activationHints as $hint): ?>
                        <li><?= escape($hint) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post">
            <?php if (count($credentialOptions) > 1): ?>
                <fieldset>
                    <legend>Zugangsdaten auswählen</legend>
                    <div class="radio-group">
                        <?php foreach ($credentialOptions as $key => $option): ?>
                            <?php $isSelected = $selectedCredentialKey === $key; ?>
                            <label class="radio-option<?= $isSelected ? ' selected' : '' ?>">
                                <input type="radio" name="credential_source" value="<?= escape($key) ?>"<?= $isSelected ? ' checked' : '' ?>>
                                <div>
                                    <div><?= escape($option['label']) ?></div>
                                    <small>Vorschau: <?= escape(redactCredentialPreview($option['credential'])) ?></small>
                                    <?php if (($option['merchant'] ?? '') !== ''): ?>
                                        <br><small>Händlercode: <?= escape((string) $option['merchant']) ?></small>
                                    <?php endif; ?>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
            <?php elseif ($selectedCredentialKey !== null): ?>
                <input type="hidden" name="credential_source" value="<?= escape($selectedCredentialKey) ?>">
            <?php endif; ?>

            <fieldset>
                <legend>Aktivierung</legend>
                <label for="activation_code">Aktivierungscode vom Terminal</label>
                <input type="text" id="activation_code" name="activation_code" value="<?= escape($submittedActivationCode) ?>" placeholder="z. B. AB12CD" required>

                <label for="label">Optionales Label im SumUp-Dashboard</label>
                <input type="text" id="label" name="label" value="<?= escape($submittedLabel) ?>" placeholder="z. B. Tresen oder Mobil">

                <label for="merchant_code">Händlercode (Format MCRXXXX – empfohlen für API-Keys)</label>
                <input type="text" id="merchant_code" name="merchant_code" value="<?= escape($submittedMerchantCode) ?>" placeholder="z. B. MCRNF79M">
            </fieldset>

            <button type="submit"<?= $credentialOptions === [] ? ' disabled' : '' ?>>Terminal koppeln</button>
        </form>

        <?php if ($activationResponse !== null): ?>
            <details>
                <summary>API-Antwort anzeigen</summary>
                <pre><?= escape(json_encode($activationResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
            </details>
        <?php endif; ?>

        <details>
            <summary>cURL-Beispiel</summary>
            <pre>curl -X POST \
  "https://api.sumup.com/v0.1/merchants/&lt;HÄNDLERCODE&gt;/readers" \
  -H "Authorization: Bearer &lt;API-KEY-ODER-TOKEN&gt;" \
  -H "Content-Type: application/json" \
  -d '{
    "activation_code": "<?= escape($curlExampleActivationCode) ?>"<?= escape($curlExampleLabelLine) ?>
}'</pre>
        </details>
    </div>
</body>
</html>
