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
        private readonly string $authMethod = 'api_key'
    ) {
        self::assertCredential($credential, $this->authMethod);

        if ($terminalSerial === '') {
            throw new RuntimeException('Missing SumUp terminal serial number.');
        }
    }

    /**
     * Retrieves the list of terminals available for the authenticated merchant account.
     *
     * @param string|null $merchantCode Optional merchant code (e.g. "MCRNF79M") used by API-key discovery.
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
    ): array {
        self::assertCredential($credential, $authMethod);

        $merchantCode = $merchantCode !== null ? trim($merchantCode) : '';
        $previousAttempts = [];

        if ($authMethod === 'api_key' && $merchantCode !== '') {
            $readersEndpoint = sprintf(
                '%s/merchants/%s/readers?limit=200',
                self::API_BASE_URL,
                rawurlencode($merchantCode)
            );

            $readersResponse = self::requestJson(
                $readersEndpoint,
                $credential,
                $authMethod,
                'GET',
                null,
                [
                    'merchant_code' => $merchantCode,
                ]
            );

            if ($readersResponse['status'] !== 404) {
                return $readersResponse;
            }

            $previousAttempts[] = self::summariseAttempt($readersResponse);
        }

        $primaryEndpoint = sprintf('%s/me/terminals?limit=200', self::API_BASE_URL);
        $primaryResponse = self::requestJson(
            $primaryEndpoint,
            $credential,
            $authMethod,
            'GET'
        );

        if ($primaryResponse['status'] !== 404 || $authMethod !== 'api_key') {
            return self::attachPreviousAttempts($primaryResponse, $previousAttempts);
        }

        $previousAttempts[] = self::summariseAttempt($primaryResponse);

        $fallbackEndpoint = sprintf('%s/me/merchant-terminals?limit=200', self::API_BASE_URL);
        $fallbackResponse = self::requestJson(
            $fallbackEndpoint,
            $credential,
            $authMethod,
            'GET'
        );

        return self::attachPreviousAttempts(
            $fallbackResponse,
            $previousAttempts,
            'Fallback auf /me/merchant-terminals, da vorherige Aufrufe mit HTTP 404 geantwortet haben.'
        );
    }

    /**
     * Activates/links a terminal reader to the authenticated merchant account using the pairing/activation code
     * shown on the device.
     *
     * @param string      $credential    API key or OAuth token.
     * @param string      $authMethod    Authentication method, either "api_key" or "oauth".
     * @param string      $activationCode Short-lived activation code from the terminal screen.
     * @param string|null $merchantCode  Optional merchant code (e.g. MCRNF79M). Required for the merchant endpoint.
     * @param string|null $label         Optional label that should appear in the SumUp dashboard.
     *
     * @return array{
     *     status:int,
     *     body:array<string,mixed>,
     *     request:array<string,mixed>,
     *     response_raw:string
     * }
     */
    public static function activateTerminal(
        string $credential,
        string $authMethod,
        string $activationCode,
        ?string $merchantCode = null,
        ?string $label = null
    ): array {
        self::assertCredential($credential, $authMethod);

        $activationCode = self::normaliseActivationCode($activationCode);

        if ($activationCode === '') {
            throw new RuntimeException('Aktivierungscode darf nicht leer sein.');
        }

        $payload = [
            'activation_code' => $activationCode,
        ];

        if ($label !== null && $label !== '') {
            $payload['label'] = $label;
        }

        $previousAttempts = [];
        $merchantCode = $merchantCode !== null ? trim($merchantCode) : '';

        if ($merchantCode !== '') {
            $merchantEndpoint = sprintf(
                '%s/merchants/%s/readers',
                self::API_BASE_URL,
                rawurlencode($merchantCode)
            );

            $merchantResponse = self::requestJson(
                $merchantEndpoint,
                $credential,
                $authMethod,
                'POST',
                $payload,
                [
                    'merchant_code' => $merchantCode,
                    'activation_code' => self::redactActivationCode($activationCode),
                ]
            );

            if ($merchantResponse['status'] !== 404) {
                return $merchantResponse;
            }

            $previousAttempts[] = self::summariseAttempt($merchantResponse);
        }

        $meActivateEndpoint = sprintf('%s/me/terminals/activate', self::API_BASE_URL);
        $meActivateResponse = self::requestJson(
            $meActivateEndpoint,
            $credential,
            $authMethod,
            'POST',
            $payload,
            [
                'activation_code' => self::redactActivationCode($activationCode),
            ]
        );

        if ($meActivateResponse['status'] !== 404 || $authMethod !== 'api_key') {
            return self::attachPreviousAttempts($meActivateResponse, $previousAttempts);
        }

        $previousAttempts[] = self::summariseAttempt($meActivateResponse);

        $fallbackEndpoint = sprintf('%s/me/terminals', self::API_BASE_URL);
        $fallbackResponse = self::requestJson(
            $fallbackEndpoint,
            $credential,
            $authMethod,
            'POST',
            $payload,
            [
                'activation_code' => self::redactActivationCode($activationCode),
                'note' => 'Fallback auf POST /me/terminals, da vorherige Aktivierungsaufrufe HTTP 404 geliefert haben.',
            ]
        );

        return self::attachPreviousAttempts($fallbackResponse, $previousAttempts);
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
            '%s/me/terminals/%s/transactions',
            self::API_BASE_URL,
            rawurlencode($this->terminalSerial)
        );

        return self::requestJson(
            $endpoint,
            $this->credential,
            $this->authMethod,
            'POST',
            $payload,
            [
                'terminal_serial' => $this->terminalSerial,
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

    private static function normaliseActivationCode(string $code): string
    {
        $code = strtoupper(trim($code));
        $code = preg_replace('/[^A-Z0-9]/', '', $code);

        return $code ?? '';
    }

    private static function redactActivationCode(string $code): string
    {
        $length = strlen($code);

        if ($length <= 4) {
            return str_repeat('•', $length);
        }

        return substr($code, 0, 2) . str_repeat('•', max(0, $length - 4)) . substr($code, -2);
    }

    /**
     * @param array{status:int,body:array<string,mixed>,request?:array<string,mixed>,response_raw:string} $response
     * @return array{status:int,body:array<string,mixed>,request?:array<string,mixed>,response_raw:string}
     */
    private static function attachPreviousAttempts(array $response, array $attempts, ?string $note = null): array
    {
        if (!isset($response['request']) || !is_array($response['request'])) {
            $response['request'] = [];
        }

        if ($note !== null && $note !== '') {
            $response['request']['note'] = $note;
        }

        if ($attempts !== []) {
            $response['request']['previous_attempts'] = $attempts;
        }

        return $response;
    }

    /**
     * @param array{status:int,body:array<string,mixed>,request?:array<string,mixed>,response_raw:string} $response
     * @return array{status:int,url:string,method:string,response:array<string,mixed>,response_raw:string,headers?:array<string,string>}
     */
    private static function summariseAttempt(array $response): array
    {
        $request = $response['request'] ?? [];

        $attempt = [
            'status' => $response['status'],
            'url' => $request['url'] ?? '',
            'method' => $request['method'] ?? 'GET',
            'response' => $response['body'],
            'response_raw' => $response['response_raw'],
        ];

        if (isset($request['headers']) && is_array($request['headers'])) {
            $attempt['headers'] = $request['headers'];
        }

        return $attempt;
    }
}
