<?php

declare(strict_types=1);

use App\TerminalStorage;

require_once __DIR__ . '/../src/TerminalStorage.php';

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
    return array_map(
        static function (string $value): string {
            return trim($value);
        },
        $data
    );
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
    } catch (\Throwable $exception) {
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

/**
 * @return array{status:int, body:array<string, mixed>|null, raw:string, error:string|null}
 */
function performSumUpGetRequest(string $apiKey, string $url): array
{
    $ch = curl_init($url);

    if ($ch === false) {
        return [
            'status' => 0,
            'body' => null,
            'raw' => '',
            'error' => 'cURL konnte nicht initialisiert werden.',
        ];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
        ],
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

/**
 * @return array{status:int, body:array<string, mixed>|null, raw:string, error:string|null}
 */
function fetchTransactionByForeignTransactionId(string $apiKey, string $foreignTransactionId): array
{
    $url = 'https://api.sumup.com/v0.1/me/transactions?foreign_transaction_id=' . rawurlencode($foreignTransactionId);

    return performSumUpGetRequest($apiKey, $url);
}

/**
 * @return array{status:int, body:array<string, mixed>|null, raw:string, error:string|null}
 */
function fetchTransactionByClientTransactionId(string $apiKey, string $clientTransactionId): array
{
    $url = 'https://api.sumup.com/v0.1/me/transactions/' . rawurlencode($clientTransactionId);

    return performSumUpGetRequest($apiKey, $url);
}

/**
 * @return array{status:int, body:array<string, mixed>|null, raw:string, error:string|null}
 */
function fetchReadersForMerchant(string $apiKey, string $merchantCode): array
{
    $url = sprintf(
        'https://api.sumup.com/v0.1/merchants/%s/readers',
        rawurlencode($merchantCode)
    );

    return performSumUpGetRequest($apiKey, $url);
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
$transactionMeta = null;
$readerLookupForm = [
    'merchant_code' => '',
    'api_key' => '',
];
$fetchedReaders = null;
$fetchedReadersRaw = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'transaction_status') {
        $terminalId = (string) ($_POST['terminal_id'] ?? '');
        $foreignTransactionId = trim((string) ($_POST['foreign_transaction_id'] ?? ''));
        $clientTransactionId = trim((string) ($_POST['client_transaction_id'] ?? ''));
        $terminal = $terminalId !== '' ? $storage->find($terminalId) : null;

        header('Content-Type: application/json; charset=UTF-8');

        if ($terminal === null) {
            echo json_encode([
                'ok' => false,
                'error' => 'Terminal nicht gefunden.',
            ]);
            exit;
        }

        if ($foreignTransactionId === '' && $clientTransactionId === '') {
            echo json_encode([
                'ok' => false,
                'error' => 'Es wurde keine Transaktions-ID übermittelt.',
            ]);
            exit;
        }

        if ($clientTransactionId !== '') {
            $status = fetchTransactionByClientTransactionId($terminal['api_key'], $clientTransactionId);
        } else {
            $status = fetchTransactionByForeignTransactionId($terminal['api_key'], $foreignTransactionId);
        }

        $body = $status['body'];
        $statusLabel = null;

        if (is_array($body)) {
            $statusLabel = $body['status'] ?? ($body['transaction_status'] ?? null);
        }

        $finalStatuses = ['SUCCESSFUL', 'PAID', 'FAILED', 'DECLINED', 'CANCELED', 'CANCELLED', 'REFUNDED'];
        $isFinal = false;

        if (is_string($statusLabel)) {
            $upper = strtoupper($statusLabel);
            $isFinal = in_array($upper, $finalStatuses, true);
        }

        $httpError = $status['status'] >= 400;
        $errorMessage = $status['error'];

        if ($httpError && $errorMessage === null) {
            $errorMessage = sprintf('SumUp hat den Status mit HTTP %d zurückgegeben.', $status['status']);
        }

        echo json_encode([
            'ok' => !$httpError && $status['error'] === null,
            'status_code' => $status['status'],
            'body' => $body,
            'raw' => $status['raw'],
            'error' => $errorMessage,
            'final' => $isFinal,
            'display_status' => $statusLabel,
        ]);
        exit;
    }

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
    } elseif ($action === 'fetch_readers') {
        $readerLookupForm = trimInput([
            'merchant_code' => (string) ($_POST['merchant_code'] ?? ''),
            'api_key' => (string) ($_POST['api_key'] ?? ''),
        ]);

        foreach (['merchant_code', 'api_key'] as $required) {
            if ($readerLookupForm[$required] === '') {
                $errors[] = sprintf('Das Feld "%s" darf nicht leer sein.', $required);
            }
        }

        if ($errors === []) {
            $response = fetchReadersForMerchant($readerLookupForm['api_key'], $readerLookupForm['merchant_code']);

            if ($response['error'] !== null || $response['status'] >= 400) {
                $errors[] = $response['error'] !== null
                    ? 'SumUp-Anfrage fehlgeschlagen: ' . $response['error']
                    : sprintf('SumUp hat die Leserliste mit HTTP %d beantwortet.', $response['status']);
            } elseif ($response['body'] === null) {
                $errors[] = 'Die Antwort von SumUp konnte nicht verarbeitet werden.';
            } else {
                $data = $response['body'];
                $items = [];

                if (isset($data['items']) && is_array($data['items'])) {
                    $items = array_values(array_filter($data['items'], 'is_array'));
                } elseif (is_array($data)) {
                    $items = [];

                    foreach ($data as $entry) {
                        if (is_array($entry)) {
                            $items[] = $entry;
                        }
                    }
                }

                $fetchedReaders = $items;
                $fetchedReadersRaw = $response['raw'];
                $successMessage = sprintf('Es wurden %d Terminal(s) vom SumUp-Konto geladen.', count($items));
            }
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
                } elseif ($checkoutResult['status'] >= 400) {
                    $errorDetail = null;

                    if (is_array($checkoutResult['body'])) {
                        if (isset($checkoutResult['body']['message']) && $checkoutResult['body']['message'] !== '') {
                            $errorDetail = (string) $checkoutResult['body']['message'];
                        } elseif (isset($checkoutResult['body']['error_message']) && $checkoutResult['body']['error_message'] !== '') {
                            $errorDetail = (string) $checkoutResult['body']['error_message'];
                        } elseif (isset($checkoutResult['body']['error_code']) && $checkoutResult['body']['error_code'] !== '') {
                            $errorDetail = 'Fehlercode: ' . (string) $checkoutResult['body']['error_code'];
                        }
                    }

                    if ($errorDetail === null || $errorDetail === '') {
                        $errorDetail = $checkoutResult['raw'] !== ''
                            ? $checkoutResult['raw']
                            : 'Unbekannte Fehlermeldung.';
                    }

                    $errors[] = sprintf(
                        'Die Zahlung wurde von SumUp abgelehnt (HTTP-Status %d): %s',
                        $checkoutResult['status'],
                        $errorDetail
                    );
                } else {
                    $clientTransactionId = null;

                    if (is_array($checkoutResult['body'] ?? null) && isset($checkoutResult['body']['client_transaction_id'])) {
                        $clientTransactionId = (string) $checkoutResult['body']['client_transaction_id'];
                    }

                    $successMessage = sprintf(
                        'Zahlung an %s gesendet (Betrag: %s %s, Foreign Transaction ID: %s%s).',
                        $terminal['label'],
                        number_format($amount['value'] / (10 ** $minorUnitValue), $minorUnitValue, ',', '.'),
                        strtoupper($paymentForm['currency']),
                        $foreignTransactionId,
                        $clientTransactionId !== null && $clientTransactionId !== ''
                            ? ', Client Transaction ID: ' . $clientTransactionId
                            : ''
                    );
                    $paymentForm['foreign_transaction_id'] = generateForeignTransactionId();
                    $transactionMeta = [
                        'terminal_id' => $terminal['id'] ?? $paymentForm['terminal_id'],
                        'foreign_transaction_id' => $foreignTransactionId,
                        'client_transaction_id' => $clientTransactionId,
                    ];
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

/**
 * @param mixed $value
 */
function h($value): string
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

        nav.top-nav {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin: 0 auto 2rem;
            flex-wrap: wrap;
            background: rgba(15, 23, 42, 0.85);
            border: 1px solid rgba(148, 163, 184, 0.25);
            border-radius: 999px;
            padding: 0.6rem 1.4rem;
            backdrop-filter: blur(8px);
        }

        nav.top-nav a {
            text-decoration: none;
            color: #e2e8f0;
            font-weight: 600;
            padding: 0.35rem 1rem;
            border-radius: 999px;
            transition: background 0.2s ease, color 0.2s ease;
        }

        nav.top-nav a:hover,
        nav.top-nav a:focus {
            background: rgba(56, 189, 248, 0.18);
            color: #f8fafc;
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

        code {
            font-family: ui-monospace, SFMono-Regular, SFMono, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: 0.9rem;
            background: rgba(15, 23, 42, 0.65);
            padding: 0.1rem 0.4rem;
            border-radius: 0.35rem;
        }

        .status-card {
            margin-top: 2rem;
        }

        .status-card .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.25rem 0.7rem;
            border-radius: 999px;
            background: rgba(56, 189, 248, 0.18);
            border: 1px solid rgba(56, 189, 248, 0.35);
            font-weight: 600;
        }

        .status-card .status-pill.error {
            background: rgba(248, 113, 113, 0.2);
            border-color: rgba(248, 113, 113, 0.4);
        }

        .status-card .status-pill.success {
            background: rgba(74, 222, 128, 0.25);
            border-color: rgba(74, 222, 128, 0.4);
        }

        .status-card pre {
            margin-top: 1rem;
            background: rgba(15, 23, 42, 0.8);
            padding: 1rem;
            border-radius: 0.75rem;
            overflow-x: auto;
        }

        .status-card .status-actions {
            display: flex;
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .hidden {
            display: none !important;
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
<nav class="top-nav">
    <a href="#home">Home</a>
    <a href="#terminals">Terminals</a>
    <a href="#settings">Einstellungen</a>
</nav>
<div class="container">
    <header id="home" style="margin-bottom: 2rem;">
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

    <section class="card" id="checkout">
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
                <p class="muted" style="margin-top: 0.25rem;">Mehrere Sätze kommasepariert eingeben. Werte > 1 werden als Prozent interpretiert.</p>

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

    <section class="card" id="terminals">
        <h2>Gespeicherte Terminals</h2>
        <?php if ($terminals === []): ?>
            <p class="muted">Noch keine Terminals hinterlegt. Lege unter „Einstellungen“ ein neues Gerät an.</p>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                    <tr>
                        <th>Bezeichnung</th>
                        <th>Reader ID</th>
                        <th>Merchant Code</th>
                        <th>Return-URL</th>
                        <th>Aktionen</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($terminals as $terminal): ?>
                        <tr>
                            <td><?= h($terminal['label']) ?></td>
                            <td><code><?= h($terminal['reader_id']) ?></code></td>
                            <td><code><?= h($terminal['merchant_code']) ?></code></td>
                            <td>
                                <?php if ($terminal['default_return_url'] !== ''): ?>
                                    <a href="<?= h($terminal['default_return_url']) ?>" target="_blank" rel="noopener noreferrer">Link öffnen</a>
                                <?php else: ?>
                                    <span class="muted">Nicht gesetzt</span>
                                <?php endif; ?>
                            </td>
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

    <section id="settings" style="margin-top: 2rem;">
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
                <h2>Terminals vom SumUp-Konto abrufen</h2>
                <form method="post" autocomplete="off" style="margin-bottom: 1.5rem;">
                    <input type="hidden" name="action" value="fetch_readers">

                    <label for="lookup-merchant-code">Merchant Code</label>
                    <input id="lookup-merchant-code" name="merchant_code" required value="<?= h($readerLookupForm['merchant_code']) ?>">

                    <label for="lookup-api-key">SumUp Secret Key (Bearer Token)</label>
                    <input id="lookup-api-key" name="api_key" required value="<?= h($readerLookupForm['api_key']) ?>">

                    <div class="actions" style="margin-top: 1.5rem;">
                        <button type="submit">Reader abrufen</button>
                    </div>
                </form>

                <?php if (is_array($fetchedReaders)): ?>
                    <?php if ($fetchedReaders === []): ?>
                        <p class="muted">Für diesen Merchant wurden keine Terminals zurückgegeben.</p>
                    <?php else: ?>
                        <div style="overflow-x: auto;">
                            <table>
                                <thead>
                                <tr>
                                    <th>Reader ID</th>
                                    <th>Bezeichnung</th>
                                    <th>Status</th>
                                    <th>Modell</th>
                                    <th>Seriennummer</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($fetchedReaders as $reader): ?>
                                    <tr>
                                        <td><?= h((string) ($reader['id'] ?? ($reader['reader_id'] ?? ''))) ?></td>
                                        <td><?= h((string) ($reader['label'] ?? ($reader['description'] ?? ''))) ?></td>
                                        <td><?= h((string) ($reader['status'] ?? '')) ?></td>
                                        <td><?= h((string) ($reader['type'] ?? ($reader['model'] ?? ''))) ?></td>
                                        <td><?= h((string) ($reader['serial_number'] ?? ($reader['serial'] ?? ''))) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <?php if ($fetchedReadersRaw !== null): ?>
                        <details style="margin-top: 1rem;">
                            <summary>Rohdaten anzeigen</summary>
                            <pre style="white-space: pre-wrap; word-break: break-word; background: #0f172a; color: #e2e8f0; padding: 1rem; border-radius: 0.5rem;"><?= h($fetchedReadersRaw) ?></pre>
                        </details>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="muted">Gib deinen Merchant Code und den API Key ein, um die verbundenen Terminals anzeigen zu lassen.</p>
                <?php endif; ?>
            </section>
        </div>

        <section class="card status-card" id="transaction-status-card">
            <h2>Echtzeit-Zahlungsstatus</h2>
            <?php if ($transactionMeta !== null): ?>
                <p>
                    Foreign Transaction ID:
                    <code><?= h($transactionMeta['foreign_transaction_id']) ?></code>
                </p>
                <?php if (!empty($transactionMeta['client_transaction_id'])): ?>
                    <p>
                        Client Transaction ID:
                        <code><?= h((string) $transactionMeta['client_transaction_id']) ?></code>
                    </p>
                <?php endif; ?>
                <div class="status-pill" data-transaction-status>Polling läuft…</div>
                <div class="status-actions">
                    <button type="button" id="refresh-status">Status aktualisieren</button>
                    <button type="button" id="stop-status" class="secondary-button hidden">Automatische Abfrage stoppen</button>
                </div>
                <pre id="transaction-status-details" class="hidden"></pre>
            <?php else: ?>
                <p class="muted">Sobald eine Zahlung gesendet wurde, erscheint hier der Live-Status.</p>
            <?php endif; ?>
        </section>
    </section>

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

    const transactionMeta = <?= json_encode($transactionMeta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const statusCard = document.getElementById('transaction-status-card');
    const statusPill = statusCard ? statusCard.querySelector('[data-transaction-status]') : null;
    const statusDetails = document.getElementById('transaction-status-details');
    const refreshButton = document.getElementById('refresh-status');
    const stopButton = document.getElementById('stop-status');
    let pollTimer = null;

    function setStatusClasses(element, state) {
        if (!element) {
            return;
        }

        element.classList.remove('error', 'success');

        if (!state) {
            return;
        }

        const normalized = state.toUpperCase();

        if (['FAILED', 'DECLINED', 'CANCELED', 'CANCELLED'].includes(normalized)) {
            element.classList.add('error');
        } else if (['SUCCESSFUL', 'PAID', 'COMPLETED'].includes(normalized)) {
            element.classList.add('success');
        }
    }

    function updateStatusView(message, payload, statusLabel) {
        if (statusPill) {
            statusPill.textContent = message;
            setStatusClasses(statusPill, statusLabel);
        }

        if (statusDetails) {
            if (payload) {
                statusDetails.textContent = JSON.stringify(payload, null, 2);
                statusDetails.classList.remove('hidden');
            } else {
                statusDetails.textContent = '';
                statusDetails.classList.add('hidden');
            }
        }
    }

    function stopPolling() {
        if (pollTimer) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }

        if (stopButton) {
            stopButton.classList.add('hidden');
        }
    }

    function scheduleNextPoll() {
        stopPolling();

        pollTimer = window.setTimeout(() => {
            requestStatus(false);
        }, 5000);

        if (stopButton) {
            stopButton.classList.remove('hidden');
        }
    }

    function requestStatus(showLoading = true) {
        if (!transactionMeta || !statusCard) {
            return;
        }

        if (showLoading) {
            updateStatusView('Status wird abgefragt…', null, null);
        }

        const formData = new FormData();
        formData.append('action', 'transaction_status');
        formData.append('terminal_id', transactionMeta.terminal_id || '');
        formData.append('foreign_transaction_id', transactionMeta.foreign_transaction_id || '');

        if (transactionMeta.client_transaction_id) {
            formData.append('client_transaction_id', transactionMeta.client_transaction_id);
        }

        fetch(window.location.href, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: formData,
        })
            .then((response) => response.json())
            .then((data) => {
                if (!data.ok) {
                    updateStatusView(data.error || 'Status konnte nicht geladen werden.', null, null);
                    stopPolling();
                    return;
                }

                const label = data.display_status || null;
                const httpCode = typeof data.status_code === 'number' ? data.status_code : null;
                let message = label ? `Aktueller Status: ${label}` : 'Antwort erhalten';

                if (httpCode) {
                    message += ` (HTTP ${httpCode})`;
                }

                updateStatusView(message, data.body || null, label);

                if (data.final) {
                    stopPolling();
                } else {
                    scheduleNextPoll();
                }
            })
            .catch(() => {
                updateStatusView('Status konnte nicht geladen werden (Netzwerkfehler).', null, null);
                stopPolling();
            });
    }

    if (transactionMeta && statusCard) {
        statusCard.classList.remove('hidden');
        requestStatus();
    }

    if (refreshButton) {
        refreshButton.addEventListener('click', () => {
            requestStatus();
        });
    }

    if (stopButton) {
        stopButton.addEventListener('click', () => {
            stopPolling();
            updateStatusView('Automatische Abfrage gestoppt.', null, null);
        });
    }
</script>
</body>
</html>
