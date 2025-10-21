<?php

declare(strict_types=1);

use App\TerminalStorage;
use SumUp\BasicAuth;

require_once __DIR__ . '/../src/BasicAuth.php';
require_once __DIR__ . '/../src/TerminalStorage.php';

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

$authConfig = $config['auth'] ?? null;

if ($authConfig !== null && !is_array($authConfig)) {
    renderFatalError('Der Abschnitt "auth" muss ein Array sein. Bitte korrigieren Sie config/config.php.');
}

BasicAuth::enforce($authConfig ?? []);

try {
    $storage = new TerminalStorage(__DIR__ . '/../var/terminals.json');
} catch (\Throwable $exception) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Die Terminalverwaltung konnte nicht initialisiert werden: ' . $exception->getMessage();
    exit;
}

/**
 * @param array<string, string> $data
 */
function trimInput(array $data): array
{
    return array_map(static fn(string $value): string => trim($value), $data);
}

function maskCredential(string $credential): string
{
    $length = strlen($credential);

    if ($length <= 8) {
        return str_repeat('•', $length);
    }

    return substr($credential, 0, 6) . '…' . substr($credential, -4);
}

function generateForeignTransactionId(): string
{
    try {
        return 'ft_' . bin2hex(random_bytes(8));
    } catch (\Throwable) {
        return 'ft_' . uniqid();
    }
}

/**
 * @return array{value:int, formatted:string}
 */
function convertAmountToMinorUnits(string $amount, int $minorUnit): array
{
    if ($minorUnit < 0 || $minorUnit > 6) {
        throw new InvalidArgumentException('Die Anzahl der Nachkommastellen muss zwischen 0 und 6 liegen.');
    }

    $normalized = str_replace(',', '.', $amount);

    if ($normalized === '' || !preg_match('/^\d+(?:\.\d+)?$/', $normalized)) {
        throw new InvalidArgumentException('Der Betrag muss eine positive Zahl sein.');
    }

    [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');

    if ($minorUnit === 0 && $fraction !== '') {
        throw new InvalidArgumentException('Für Beträge ohne Nachkommastellen darf keine Dezimalstelle angegeben werden.');
    }

    if (strlen($fraction) > $minorUnit) {
        throw new InvalidArgumentException(sprintf('Maximal %d Nachkommastellen erlaubt.', $minorUnit));
    }

    $fraction = substr($fraction . str_repeat('0', $minorUnit), 0, $minorUnit);

    $scale = $minorUnit > 0 ? 10 ** $minorUnit : 1;
    $value = ((int) $whole) * $scale + ($fraction === '' ? 0 : (int) $fraction);

    if ($value <= 0) {
        throw new InvalidArgumentException('Der Betrag muss größer als 0 sein.');
    }

    return [
        'value' => $value,
        'formatted' => number_format($value / $scale, $minorUnit, ',', '.'),
    ];
}

/**
 * @return float[]
 */
function parseTipRates(string $input): array
{
    if ($input === '') {
        return [];
    }

    $parts = preg_split('/[\s,;]+/', $input);
    $rates = [];

    if ($parts === false) {
        return $rates;
    }

    foreach ($parts as $part) {
        $part = trim(str_replace(',', '.', $part));

        if ($part === '') {
            continue;
        }

        if (!is_numeric($part)) {
            throw new InvalidArgumentException(sprintf('Ungültiger Trinkgeldsatz: %s', $part));
        }

        $rate = (float) $part;

        if ($rate > 1) {
            $rate /= 100;
        }

        if ($rate <= 0 || $rate >= 1) {
            throw new InvalidArgumentException(sprintf('Trinkgeldsätze müssen zwischen 0 und 1 liegen: %s', $part));
        }

        $rates[] = round($rate, 4);
    }

    return array_values(array_unique($rates));
}

/**
 * @param array<string, mixed> $payload
 * @return array{status:int, body:array<string, mixed>|null, raw:string, error:string|null}
 */
function sendCheckoutRequest(string $apiKey, string $merchantCode, string $readerId, array $payload): array
{
    $endpoint = sprintf(
        'https://api.sumup.com/v0.1/merchants/%s/readers/%s/checkout',
        rawurlencode($merchantCode),
        rawurlencode($readerId)
    );

    $ch = curl_init($endpoint);

    if ($ch === false) {
        return [
            'status' => 0,
            'body' => null,
            'raw' => '',
            'error' => 'cURL konnte nicht initialisiert werden.',
        ];
    }

    $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);

    if ($jsonPayload === false) {
        curl_close($ch);

        return [
            'status' => 0,
            'body' => null,
            'raw' => '',
            'error' => 'Die Anfrage konnte nicht serialisiert werden.',
        ];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => $jsonPayload,
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return [
            'status' => $status,
            'body' => null,
            'raw' => '',
            'error' => $error !== '' ? $error : 'Unbekannter cURL-Fehler.',
        ];
    }

    $decoded = json_decode($response, true);

    return [
        'status' => $status,
        'body' => is_array($decoded) ? $decoded : null,
        'raw' => $response,
        'error' => $error !== '' ? $error : null,
    ];
}

$terminalForm = [
    'label' => '',
    'merchant_code' => '',
    'reader_id' => '',
    'app_id' => '',
    'affiliate_key' => '',
    'api_key' => '',
    'default_return_url' => '',
];

$paymentForm = [
    'terminal_id' => '',
    'amount' => '',
    'currency' => 'EUR',
    'minor_unit' => '2',
    'description' => '',
    'return_url' => '',
    'tip_rates' => '',
    'tip_timeout' => '',
    'foreign_transaction_id' => generateForeignTransactionId(),
];

$errors = [];
$successMessage = null;
$checkoutResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_terminal') {
        $terminalForm = trimInput([
            'label' => (string) ($_POST['label'] ?? ''),
            'merchant_code' => (string) ($_POST['merchant_code'] ?? ''),
            'reader_id' => (string) ($_POST['reader_id'] ?? ''),
            'app_id' => (string) ($_POST['app_id'] ?? ''),
            'affiliate_key' => (string) ($_POST['affiliate_key'] ?? ''),
            'api_key' => (string) ($_POST['api_key'] ?? ''),
            'default_return_url' => (string) ($_POST['default_return_url'] ?? ''),
        ]);

        foreach (['label', 'merchant_code', 'reader_id', 'app_id', 'affiliate_key', 'api_key'] as $required) {
            if ($terminalForm[$required] === '') {
                $errors[] = sprintf('Das Feld "%s" darf nicht leer sein.', $required);
            }
        }

        if ($terminalForm['default_return_url'] !== '' && !filter_var($terminalForm['default_return_url'], FILTER_VALIDATE_URL)) {
            $errors[] = 'Die Standard-Return-URL ist ungültig.';
        }

        if ($errors === []) {
            $storage->add($terminalForm);
            $successMessage = 'Terminal wurde gespeichert.';
            $terminalForm = [
                'label' => '',
                'merchant_code' => '',
                'reader_id' => '',
                'app_id' => '',
                'affiliate_key' => '',
                'api_key' => '',
                'default_return_url' => '',
            ];
        }
    } elseif ($action === 'delete_terminal') {
        $terminalId = (string) ($_POST['terminal_id'] ?? '');

        if ($terminalId === '') {
            $errors[] = 'Es wurde kein Terminal zum Löschen ausgewählt.';
        } else {
            $storage->remove($terminalId);
            $successMessage = 'Terminal wurde entfernt.';
        }
    } elseif ($action === 'send_payment') {
        $paymentForm = trimInput([
            'terminal_id' => (string) ($_POST['terminal_id'] ?? ''),
            'amount' => (string) ($_POST['amount'] ?? ''),
            'currency' => (string) ($_POST['currency'] ?? 'EUR'),
            'minor_unit' => (string) ($_POST['minor_unit'] ?? '2'),
            'description' => (string) ($_POST['description'] ?? ''),
            'return_url' => (string) ($_POST['return_url'] ?? ''),
            'tip_rates' => (string) ($_POST['tip_rates'] ?? ''),
            'tip_timeout' => (string) ($_POST['tip_timeout'] ?? ''),
            'foreign_transaction_id' => (string) ($_POST['foreign_transaction_id'] ?? ''),
        ]);

        if ($paymentForm['terminal_id'] === '') {
            $errors[] = 'Bitte wählen Sie ein Terminal aus.';
        }

        $terminal = $paymentForm['terminal_id'] !== '' ? $storage->find($paymentForm['terminal_id']) : null;

        if ($terminal === null) {
            $errors[] = 'Das ausgewählte Terminal wurde nicht gefunden.';
        }

        $minorUnit = ctype_digit($paymentForm['minor_unit']) ? (int) $paymentForm['minor_unit'] : null;

        if ($minorUnit === null) {
            $errors[] = 'Die Anzahl der Nachkommastellen ist ungültig.';
        }

        if ($paymentForm['currency'] === '') {
            $errors[] = 'Bitte geben Sie eine Währung an (z. B. EUR).';
        }

        if ($paymentForm['return_url'] !== '' && !filter_var($paymentForm['return_url'], FILTER_VALIDATE_URL)) {
            $errors[] = 'Die Return-URL ist ungültig.';
        }

        $foreignTransactionId = $paymentForm['foreign_transaction_id'] !== ''
            ? $paymentForm['foreign_transaction_id']
            : generateForeignTransactionId();

        if ($errors === []) {
            try {
                $minorUnitValue = $minorUnit ?? 2;
                $amount = convertAmountToMinorUnits($paymentForm['amount'], $minorUnitValue);
            } catch (\Throwable $amountException) {
                $errors[] = $amountException->getMessage();
            }

            try {
                $tipRates = parseTipRates($paymentForm['tip_rates']);
            } catch (\Throwable $tipException) {
                $errors[] = $tipException->getMessage();
            }

            if ($errors === [] && isset($amount, $terminal)) {
                $minorUnitValue = $minorUnit ?? 2;
                $payload = [
                    'affiliate' => [
                        'app_id' => $terminal['app_id'],
                        'foreign_transaction_id' => $foreignTransactionId,
                        'key' => $terminal['affiliate_key'],
                        'tags' => new stdClass(),
                    ],
                    'total_amount' => [
                        'currency' => strtoupper($paymentForm['currency']),
                        'minor_unit' => $minorUnitValue,
                        'value' => $amount['value'],
                    ],
                ];

                if ($paymentForm['description'] !== '') {
                    $payload['description'] = $paymentForm['description'];
                }

                $returnUrl = $paymentForm['return_url'] !== ''
                    ? $paymentForm['return_url']
                    : ($terminal['default_return_url'] ?? '');

                if ($returnUrl !== '') {
                    $payload['return_url'] = $returnUrl;
                    $paymentForm['return_url'] = $returnUrl;
                }

                if ($tipRates !== []) {
                    $payload['tip_rates'] = $tipRates;
                }

                if ($paymentForm['tip_timeout'] !== '') {
                    $tipTimeout = (int) $paymentForm['tip_timeout'];

                    if ($tipTimeout > 0) {
                        $payload['tip_timeout'] = $tipTimeout;
                    }
                }

                $checkoutResult = sendCheckoutRequest(
                    $terminal['api_key'],
                    $terminal['merchant_code'],
                    $terminal['reader_id'],
                    $payload
                );

                if ($checkoutResult['error'] !== null) {
                    $errors[] = 'Die Zahlung konnte nicht gesendet werden: ' . $checkoutResult['error'];
                } else {
                    $successMessage = sprintf(
                        'Zahlung an %s gesendet (Betrag: %s %s, Foreign Transaction ID: %s).',
                        $terminal['label'],
                        number_format($amount['value'] / (10 ** $minorUnitValue), $minorUnitValue, ',', '.'),
                        strtoupper($paymentForm['currency']),
                        $foreignTransactionId
                    );
                    $paymentForm['foreign_transaction_id'] = generateForeignTransactionId();
                }
            }
        }
    }
}

$terminals = $storage->all();

if ($paymentForm['terminal_id'] === '' && $terminals !== []) {
    $paymentForm['terminal_id'] = $terminals[0]['id'];
}

if ($paymentForm['return_url'] === '' && $terminals !== []) {
    $first = $terminals[0]['default_return_url'] ?? '';
    if ($first !== '') {
        $paymentForm['return_url'] = $first;
    }
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

header('Content-Type: text/html; charset=UTF-8');

$terminalReturnUrls = [];
foreach ($terminals as $terminal) {
    $terminalReturnUrls[$terminal['id']] = $terminal['default_return_url'] ?? '';
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SumUp Terminal Checkout</title>
    <style>
        :root {
            color-scheme: light dark;
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            line-height: 1.6;
        }

        body {
            margin: 0;
            background: #0f172a;
            color: #e2e8f0;
            padding: 2rem 1rem 4rem;
        }

        h1, h2 {
            margin-top: 0;
        }

        a {
            color: #38bdf8;
        }

        .container {
            max-width: 1100px;
            margin: 0 auto;
        }

        .grid {
            display: grid;
            gap: 1.5rem;
        }

        @media (min-width: 900px) {
            .grid-two {
                grid-template-columns: 1fr 1fr;
            }
        }

        .card {
            background: #111c33;
            border-radius: 1rem;
            padding: 1.75rem;
            box-shadow: 0 1.5rem 3rem rgba(15, 23, 42, 0.35);
        }

        label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.35rem;
        }

        input, select, textarea, button {
            width: 100%;
            padding: 0.6rem 0.75rem;
            border-radius: 0.6rem;
            border: 1px solid rgba(148, 163, 184, 0.35);
            background: rgba(15, 23, 42, 0.75);
            color: inherit;
            font: inherit;
        }

        textarea {
            min-height: 5.5rem;
        }

        button {
            cursor: pointer;
            background: linear-gradient(135deg, #0ea5e9, #38bdf8);
            color: #0b1120;
            font-weight: 700;
            border: none;
            transition: transform 0.12s ease, box-shadow 0.12s ease;
        }

        button:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 24px rgba(14, 165, 233, 0.3);
        }

        .actions {
            display: flex;
            gap: 1rem;
        }

        .actions button[type="submit"] {
            flex: 1 1 auto;
        }

        .message {
            border-radius: 0.8rem;
            padding: 1rem 1.2rem;
            margin-bottom: 1.2rem;
        }

        .message.error {
            background: rgba(239, 68, 68, 0.18);
            border: 1px solid rgba(248, 113, 113, 0.4);
        }

        .message.success {
            background: rgba(34, 197, 94, 0.2);
            border: 1px solid rgba(74, 222, 128, 0.45);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 0.6rem 0.75rem;
            border-bottom: 1px solid rgba(148, 163, 184, 0.25);
            text-align: left;
        }

        th {
            font-weight: 700;
            color: #cbd5f5;
        }

        .response-block {
            margin-top: 1.5rem;
            font-size: 0.95rem;
        }

        pre {
            background: rgba(15, 23, 42, 0.75);
            border-radius: 0.8rem;
            padding: 1rem;
            overflow: auto;
        }

        .muted {
            color: #94a3b8;
            font-size: 0.9rem;
        }

        .inline-form {
            display: inline;
        }

        .secondary-button {
            background: rgba(148, 163, 184, 0.2);
            color: inherit;
            border: 1px solid rgba(148, 163, 184, 0.45);
        }

        .secondary-button:hover {
            box-shadow: none;
            transform: none;
            background: rgba(148, 163, 184, 0.3);
        }
    </style>
</head>
<body>
<div class="container">
    <header style="margin-bottom: 2rem;">
        <h1>SumUp Terminal Checkout</h1>
        <p class="muted">Speichere deine Terminals und schicke Zahlungsanforderungen an die SumUp-API – ganz ohne SSH oder cURL.</p>
    </header>

    <?php if ($errors !== []): ?>
        <div class="message error">
            <strong>Es sind Fehler aufgetreten:</strong>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= h($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($successMessage !== null && $errors === []): ?>
        <div class="message success">
            <?= h($successMessage) ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-two">
        <section class="card">
            <h2>Neues Terminal speichern</h2>
            <form method="post" autocomplete="off">
                <input type="hidden" name="action" value="add_terminal">

                <label for="label">Bezeichnung</label>
                <input id="label" name="label" required value="<?= h($terminalForm['label']) ?>">

                <label for="merchant_code">Merchant Code (z. B. MCRNF79M)</label>
                <input id="merchant_code" name="merchant_code" required value="<?= h($terminalForm['merchant_code']) ?>">

                <label for="reader_id">Reader ID</label>
                <input id="reader_id" name="reader_id" required value="<?= h($terminalForm['reader_id']) ?>">

                <label for="app_id">Affiliate App ID</label>
                <input id="app_id" name="app_id" required value="<?= h($terminalForm['app_id']) ?>">

                <label for="affiliate_key">Affiliate Key</label>
                <input id="affiliate_key" name="affiliate_key" required value="<?= h($terminalForm['affiliate_key']) ?>">

                <label for="api_key">SumUp Secret Key (Bearer Token)</label>
                <input id="api_key" name="api_key" required value="<?= h($terminalForm['api_key']) ?>">

                <label for="default_return_url">Standard-Return-URL (optional)</label>
                <input id="default_return_url" name="default_return_url" value="<?= h($terminalForm['default_return_url']) ?>" placeholder="https://example.com/webhook">

                <div class="actions" style="margin-top: 1.5rem;">
                    <button type="submit">Terminal speichern</button>
                </div>
            </form>
        </section>

        <section class="card">
            <h2>Gespeicherte Terminals</h2>
            <?php if ($terminals === []): ?>
                <p class="muted">Noch keine Terminals hinterlegt. Lege zuerst rechts ein neues Gerät an.</p>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                        <tr>
                            <th>Bezeichnung</th>
                            <th>Merchant</th>
                            <th>Reader</th>
                            <th>Affiliate App</th>
                            <th>API Key</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($terminals as $terminal): ?>
                            <tr>
                                <td><?= h($terminal['label']) ?></td>
                                <td><?= h($terminal['merchant_code']) ?></td>
                                <td><?= h($terminal['reader_id']) ?></td>
                                <td><?= h($terminal['app_id']) ?></td>
                                <td><?= h(maskCredential($terminal['api_key'])) ?></td>
                                <td>
                                    <form class="inline-form" method="post" onsubmit="return confirm('Terminal wirklich löschen?');">
                                        <input type="hidden" name="action" value="delete_terminal">
                                        <input type="hidden" name="terminal_id" value="<?= h($terminal['id']) ?>">
                                        <button type="submit" class="secondary-button">Entfernen</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <section class="card" style="margin-top: 2rem;">
        <h2>Zahlung an Terminal senden</h2>
        <?php if ($terminals === []): ?>
            <p class="muted">Bitte speichere zunächst mindestens ein Terminal, um Zahlungen zu verschicken.</p>
        <?php else: ?>
            <form method="post" autocomplete="off">
                <input type="hidden" name="action" value="send_payment">

                <label for="payment-terminal">Terminal</label>
                <select id="payment-terminal" name="terminal_id" required>
                    <?php foreach ($terminals as $terminal): ?>
                        <option value="<?= h($terminal['id']) ?>" <?= $paymentForm['terminal_id'] === $terminal['id'] ? 'selected' : '' ?>>
                            <?= h($terminal['label'] . ' – ' . $terminal['reader_id']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="amount">Betrag (z. B. 50,33)</label>
                <input id="amount" name="amount" required value="<?= h($paymentForm['amount']) ?>">

                <div class="grid grid-two" style="margin-top: 1rem;">
                    <div>
                        <label for="currency">Währung</label>
                        <input id="currency" name="currency" required value="<?= h($paymentForm['currency']) ?>">
                    </div>
                    <div>
                        <label for="minor_unit">Nachkommastellen</label>
                        <input id="minor_unit" name="minor_unit" required value="<?= h($paymentForm['minor_unit']) ?>">
                    </div>
                </div>

                <label for="description" style="margin-top: 1rem;">Beschreibung (optional)</label>
                <input id="description" name="description" value="<?= h($paymentForm['description']) ?>">

                <label for="return_url" style="margin-top: 1rem;">Return-URL (optional)</label>
                <input id="return_url" name="return_url" value="<?= h($paymentForm['return_url']) ?>" placeholder="https://deine-app.de/webhook">

                <label for="foreign_transaction_id" style="margin-top: 1rem;">Foreign Transaction ID</label>
                <input id="foreign_transaction_id" name="foreign_transaction_id" value="<?= h($paymentForm['foreign_transaction_id']) ?>" required>
                <p class="muted" style="margin-top: 0.25rem;">Pflichtfeld. Wird kein Wert angegeben, erzeugt die Anwendung automatisch eine eindeutige ID.</p>

                <label for="tip_rates" style="margin-top: 1rem;">Trinkgeldsätze (optional)</label>
                <input id="tip_rates" name="tip_rates" value="<?= h($paymentForm['tip_rates']) ?>" placeholder="Beispiel: 5,10,15">
                <p class="muted" style="margin-top: 0.25rem;">Mehrere Sätze kommasepariert eingeben. Werte &gt; 1 werden als Prozent interpretiert.</p>

                <label for="tip_timeout" style="margin-top: 1rem;">Trinkgeld-Timeout in Sekunden (optional)</label>
                <input id="tip_timeout" name="tip_timeout" value="<?= h($paymentForm['tip_timeout']) ?>" placeholder="60">

                <div class="actions" style="margin-top: 1.5rem;">
                    <button type="submit">Zahlung senden</button>
                </div>
            </form>
        <?php endif; ?>

        <?php if ($checkoutResult !== null): ?>
            <div class="response-block">
                <h3>Antwort von SumUp</h3>
                <p>Statuscode: <strong><?= h((string) $checkoutResult['status']) ?></strong></p>
                <?php if ($checkoutResult['body'] !== null): ?>
                    <pre><?= h(json_encode($checkoutResult['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
                <?php else: ?>
                    <pre><?= h($checkoutResult['raw']) ?></pre>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<script>
    const terminalReturnUrls = <?= json_encode($terminalReturnUrls, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const terminalSelect = document.getElementById('payment-terminal');
    const returnUrlInput = document.getElementById('return_url');

    if (terminalSelect && returnUrlInput) {
        terminalSelect.addEventListener('change', () => {
            const selected = terminalSelect.value;
            const storedUrl = terminalReturnUrls[selected] || '';

            if (storedUrl !== '' && returnUrlInput.value === '') {
                returnUrlInput.value = storedUrl;
            }
        });
    }
</script>
</body>
</html>
