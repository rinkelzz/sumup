<?php

declare(strict_types=1);

namespace SumUp;

use RuntimeException;

final class SumUpTerminalClient
{
    private const API_BASE_URL = 'https://api.sumup.com/v0.1';

    public function __construct(
        private readonly string $credential,
        private readonly string $terminalSerial,
        private readonly string $authMethod = 'api_key',
        private readonly string $merchantCode = ''
    ) {
        self::assertCredential($credential, $this->authMethod);

        if ($terminalSerial === '') {
            throw new RuntimeException('Missing SumUp terminal serial number.');
        }

        if ($this->merchantCode === '') {
            throw new RuntimeException('Missing SumUp merchant code.');
        }
    }

    /**
     * Retrieves the list of terminals available for the authenticated merchant account.
     *
     * @return array{
     *     status:int,
     *     body:array<string,mixed>,
     *     request:array<string,mixed>,
     *     response_raw:string
     * }
     */
    public static function listTerminals(
        string $credential,
        string $authMethod = 'api_key',
        ?string $merchantCode = null
    ): array
    {
        self::assertCredential($credential, $authMethod);

        $merchantCode = trim((string) $merchantCode);

        if ($merchantCode === '') {
            throw new RuntimeException('Missing SumUp merchant code.');
        }

        $primaryEndpoint = sprintf(
            '%s/me/terminals?limit=200&merchant_code=%s',
            self::API_BASE_URL,
            rawurlencode($merchantCode)
        );
        $primaryResponse = self::requestJson(
            $primaryEndpoint,
            $credential,
            $authMethod,
            'GET',
            null,
            [
                'merchant_code' => $merchantCode,
            ]
        );

        if ($primaryResponse['status'] !== 404 || $authMethod !== 'api_key') {
            return $primaryResponse;
        }

        $fallbackEndpoint = sprintf(
            '%s/me/merchant-terminals?limit=200&merchant_code=%s',
            self::API_BASE_URL,
            rawurlencode($merchantCode)
        );
        $fallbackResponse = self::requestJson(
            $fallbackEndpoint,
            $credential,
            $authMethod,
            'GET',
            null,
            [
                'merchant_code' => $merchantCode,
            ]
        );

        if (!isset($fallbackResponse['request']) || !is_array($fallbackResponse['request'])) {
            $fallbackResponse['request'] = [];
        }

        $previousAttempt = [
            'status' => $primaryResponse['status'],
            'url' => $primaryResponse['request']['url'] ?? $primaryEndpoint,
            'method' => $primaryResponse['request']['method'] ?? 'GET',
            'merchant_code' => $merchantCode,
            'response' => $primaryResponse['body'],
            'response_raw' => $primaryResponse['response_raw'],
        ];

        if (isset($primaryResponse['request']['headers'])) {
            $previousAttempt['headers'] = $primaryResponse['request']['headers'];
        }

        $fallbackResponse['request']['note'] = 'Fallback auf /me/merchant-terminals, da /me/terminals mit HTTP 404 geantwortet hat.';
        $fallbackResponse['request']['previous_attempts'] = [$previousAttempt];

        return $fallbackResponse;
    }

    /**
     * Sends a payment request to the configured SumUp terminal.
     *
     * @param float       $amount       Amount in major units (e.g. Euros).
     * @param string      $currency     ISO 4217 currency code, e.g. "EUR".
     * @param string|null $externalId   Optional unique identifier for the payment.
     * @param string|null $description  Optional description that appears in SumUp dashboard.
     * @param float|null  $tipAmount    Optional tip amount in major units.
     *
     * @return array{
     *     status:int,
     *     body:array<string,mixed>,
     *     request:array<string,mixed>,
     *     response_raw:string
     * }
     */
    public function sendPayment(
        float $amount,
        string $currency = 'EUR',
        ?string $externalId = null,
        ?string $description = null,
        ?float $tipAmount = null
    ): array {
        if ($amount <= 0) {
            throw new RuntimeException('Amount must be greater than zero.');
        }

        $payload = [
            'amount' => round($amount, 2),
            'currency' => strtoupper($currency),
            'transaction_type' => 'SALE',
        ];

        if ($tipAmount !== null) {
            $payload['tip_amount'] = round($tipAmount, 2);
        }

        if ($externalId !== null && $externalId !== '') {
            $payload['external_id'] = $externalId;
        }

        if ($description !== null && $description !== '') {
            $payload['description'] = $description;
        }

        $endpoint = sprintf(
            '%s/me/terminals/%s/transactions?merchant_code=%s',
            self::API_BASE_URL,
            rawurlencode($this->terminalSerial),
            rawurlencode($this->merchantCode)
        );

        return self::requestJson(
            $endpoint,
            $this->credential,
            $this->authMethod,
            'POST',
            $payload,
            [
                'terminal_serial' => $this->terminalSerial,
                'merchant_code' => $this->merchantCode,
            ]
        );
    }

    /**
     * @param array<string,mixed>|null $payload
     * @param array<string,mixed>      $extraRequestInfo
     *
     * @return array{
     *     status:int,
     *     body:array<string,mixed>,
     *     request:array<string,mixed>,
     *     response_raw:string
     * }
     */
    private static function requestJson(
        string $url,
        string $credential,
        string $authMethod,
        string $method,
        ?array $payload = null,
        array $extraRequestInfo = []
    ): array {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('Die PHP-Extension "curl" wird für die Kommunikation mit SumUp benötigt.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialise cURL.');
        }

        $encodedPayload = null;
        if ($payload !== null) {
            $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        }

        $headers = [
            'Accept: application/json',
            sprintf('Authorization: %s', self::buildAuthorizationHeaderFor($credential)),
        ];

        if ($encodedPayload !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
        ];

        if ($encodedPayload !== null) {
            $options[CURLOPT_POSTFIELDS] = $encodedPayload;
        }

        curl_setopt_array($ch, $options);

        $responseBody = curl_exec($ch);
        if ($responseBody === false) {
            $message = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('SumUp API request failed: ' . $message);
        }

        $statusCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: 0;
        curl_close($ch);

        /** @var array<string,mixed>|null $decoded */
        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            $decoded = [
                'raw' => $responseBody,
            ];
        }

        $requestDetails = array_merge(
            [
                'url' => $url,
                'method' => strtoupper($method),
                'auth_method' => $authMethod,
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => sprintf('Bearer %s', self::redactCredential($credential)),
                ],
            ],
            $extraRequestInfo
        );

        if ($encodedPayload !== null && $payload !== null) {
            $requestDetails['payload'] = $payload;
            $requestDetails['headers']['Content-Type'] = 'application/json';
        }

        return [
            'status' => $statusCode,
            'body' => $decoded,
            'request' => $requestDetails,
            'response_raw' => $responseBody,
        ];
    }

    private static function assertCredential(string $credential, string $authMethod): void
    {
        if (!in_array($authMethod, ['api_key', 'oauth'], true)) {
            throw new RuntimeException('Unsupported SumUp authentication method.');
        }

        if ($credential === '') {
            throw new RuntimeException('Missing SumUp credentials.');
        }

        if ($authMethod === 'api_key' && str_starts_with($credential, 'sum_pk_')) {
            throw new RuntimeException('Der konfigurierte SumUp-Schlüssel beginnt mit "sum_pk_". Bitte verwenden Sie den geheimen API-Key mit dem Präfix "sum_sk_".');
        }
    }

    private static function buildAuthorizationHeaderFor(string $credential): string
    {
        // SumUp accepts API keys and OAuth tokens via Bearer authorization.
        return 'Bearer ' . $credential;
    }

    private static function redactCredential(string $credential): string
    {
        $length = strlen($credential);

        if ($length === 0) {
            return '';
        }

        if ($length <= 8) {
            return str_repeat('•', $length);
        }

        $start = substr($credential, 0, 4);
        $end = substr($credential, -4);

        return sprintf('%s%s%s', $start, str_repeat('•', $length - 8), $end);
    }
}
