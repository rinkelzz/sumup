<?php

declare(strict_types=1);

use App\TransactionStorage;

require_once __DIR__ . '/../src/TransactionStorage.php';

/**
 * @return array<string, string>
 */
function requestHeaders(): array
{
    if (function_exists('getallheaders')) {
        $headers = getallheaders();

        if (is_array($headers)) {
            return $headers;
        }
    }

    $headers = [];

    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') !== 0) {
            continue;
        }

        $normalizedKey = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
        $headers[$normalizedKey] = (string) $value;
    }

    if (isset($_SERVER['CONTENT_TYPE'])) {
        $headers['Content-Type'] = (string) $_SERVER['CONTENT_TYPE'];
    }

    if (isset($_SERVER['CONTENT_LENGTH'])) {
        $headers['Content-Length'] = (string) $_SERVER['CONTENT_LENGTH'];
    }

    return $headers;
}

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (strtoupper($requestMethod) !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}

$rawBody = file_get_contents('php://input');

if ($rawBody === false) {
    $rawBody = '';
}

$headers = requestHeaders();
$contentType = '';

if (isset($headers['Content-Type'])) {
    $contentType = $headers['Content-Type'];
} elseif (isset($_SERVER['CONTENT_TYPE'])) {
    $contentType = (string) $_SERVER['CONTENT_TYPE'];
}

$parsedPayload = null;
$parseError = null;
$payloadFormat = null;

if ($contentType !== '' && stripos($contentType, 'application/json') === 0) {
    $decoded = json_decode($rawBody, true);

    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $parsedPayload = $decoded;
        $payloadFormat = 'json';
    } else {
        $parseError = json_last_error_msg();
    }
} else {
    $formData = [];
    parse_str($rawBody, $formData);

    if (is_array($formData)) {
        $parsedPayload = $formData;
        $payloadFormat = 'form';
    }
}

$requestId = null;

foreach (['Sumup-Request-Id', 'SumUp-Request-Id', 'X-Request-Id', 'X-Correlation-Id'] as $headerName) {
    if (isset($headers[$headerName]) && $headers[$headerName] !== '') {
        $requestId = (string) $headers[$headerName];
        break;
    }
}

$storageKey = null;

if (is_array($parsedPayload)) {
    foreach (['foreign_transaction_id', 'foreignTransactionId', 'transaction_code', 'transactionCode', 'transaction_id', 'transactionId'] as $key) {
        if (isset($parsedPayload[$key]) && (is_string($parsedPayload[$key]) || is_numeric($parsedPayload[$key]))) {
            $storageKey = (string) $parsedPayload[$key];
            break;
        }
    }
}

if ($storageKey === null) {
    if ($requestId !== null) {
        $storageKey = $requestId;
    } else {
        try {
            $storageKey = 'request_' . bin2hex(random_bytes(8));
        } catch (\Throwable $exception) {
            $storageKey = 'request_' . uniqid('', true);
        }
    }
}

$record = [
    'received_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
    'request_id' => $requestId,
    'storage_key' => $storageKey,
    'method' => $requestMethod,
    'content_type' => $contentType !== '' ? $contentType : null,
    'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
    'is_secure' => isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off',
    'payload_format' => $payloadFormat,
    'headers' => $headers,
    'raw_body' => $rawBody,
    'payload' => $parsedPayload,
];

if ($parseError !== null) {
    $record['parse_error'] = $parseError;
}

try {
    $storage = new TransactionStorage(__DIR__ . '/../var/transactions');
    $storage->append($storageKey, $record);
} catch (\Throwable $exception) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Der Webhook konnte nicht gespeichert werden: ' . $exception->getMessage();
    exit;
}

http_response_code(204);
