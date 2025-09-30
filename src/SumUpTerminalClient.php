<?php

declare(strict_types=1);

namespace SumUp;

use RuntimeException;

final class SumUpTerminalClient
{
    private const API_BASE_URL = 'https://api.sumup.com/v0.1';

    public function __construct(
        private readonly string $accessToken,
        private readonly string $terminalSerial
    ) {
        if ($accessToken === '') {
            throw new RuntimeException('Missing SumUp access token.');
        }

        if ($terminalSerial === '') {
            throw new RuntimeException('Missing SumUp terminal serial number.');
        }
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
     * @return array{status:int, body:array<string,mixed>}
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
            '%s/terminals/%s/transactions',
            self::API_BASE_URL,
            rawurlencode($this->terminalSerial)
        );

        return $this->postJson($endpoint, $payload);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{status:int, body:array<string,mixed>}
     */
    private function postJson(string $url, array $payload): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialise cURL.');
        }

        $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $encodedPayload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $this->accessToken,
            ],
        ]);

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

        return [
            'status' => $statusCode,
            'body' => $decoded,
        ];
    }
}
