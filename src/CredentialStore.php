<?php

declare(strict_types=1);

namespace SumUp;

use RuntimeException;

final class CredentialStore
{
    private string $credentialFile;
    private string $keyFile;

    public function __construct(string $credentialFile, string $keyFile)
    {
        if (!extension_loaded('sodium')) {
            throw new RuntimeException('Die PHP-Extension "sodium" wird für die sichere Speicherung benötigt.');
        }

        $this->credentialFile = $credentialFile;
        $this->keyFile = $keyFile;
    }

    public function hasApiKey(): bool
    {
        $data = $this->readCredentialFile();

        return isset($data['ciphertext'], $data['nonce']) && $data['ciphertext'] !== '' && $data['nonce'] !== '';
    }

    /**
     * @return array{merchant_id: string, api_key: string, updated_at?: string}|null
     *     `merchant_id` enthält optional den Händlercode (z. B. MCRNF79M) für Terminalabfragen.
     */
    public function getApiCredential(): ?array
    {
        $data = $this->readCredentialFile();

        if (!isset($data['ciphertext'], $data['nonce'])) {
            return null;
        }

        $ciphertext = base64_decode((string) $data['ciphertext'], true);
        $nonce = base64_decode((string) $data['nonce'], true);

        if ($ciphertext === false || $nonce === false) {
            return null;
        }

        $key = $this->loadOrCreateKey();
        $apiKey = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);

        if ($apiKey === false) {
            return null;
        }

        $result = [
            'merchant_id' => isset($data['merchant_id']) ? (string) $data['merchant_id'] : '',
            'api_key' => $apiKey,
        ];

        if (isset($data['updated_at'])) {
            $result['updated_at'] = (string) $data['updated_at'];
        }

        return $result;
    }

    public function saveApiKey(string $merchantId, string $apiKey): void
    {
        $merchantId = trim($merchantId);
        $apiKey = trim($apiKey);

        if ($apiKey === '') {
            throw new RuntimeException('API-Key darf nicht leer sein.');
        }

        if (str_starts_with($apiKey, 'sum_pk_')) {
            throw new RuntimeException('Der eingegebene Schlüssel beginnt mit "sum_pk_". Bitte verwenden Sie den geheimen SumUp-API-Key mit dem Präfix "sum_sk_".');
        }

        $key = $this->loadOrCreateKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($apiKey, $nonce, $key);

        $payload = [
            'merchant_id' => $merchantId,
            'nonce' => base64_encode($nonce),
            'ciphertext' => base64_encode($ciphertext),
            'updated_at' => (new \DateTimeImmutable('now'))->format('c'),
        ];

        $this->writeCredentialFile($payload);
    }

    public function clear(): void
    {
        if (is_file($this->credentialFile)) {
            unlink($this->credentialFile);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readCredentialFile(): array
    {
        if (!is_file($this->credentialFile)) {
            return [];
        }

        $content = file_get_contents($this->credentialFile);

        if ($content === false || $content === '') {
            return [];
        }

        $data = json_decode($content, true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeCredentialFile(array $payload): void
    {
        $directory = dirname($this->credentialFile);

        if ($directory !== '' && !is_dir($directory)) {
            if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new RuntimeException('Speicherverzeichnis konnte nicht erstellt werden: ' . $directory);
            }
        }

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new RuntimeException('API-Key konnte nicht serialisiert werden.');
        }

        if (file_put_contents($this->credentialFile, $json . "\n", LOCK_EX) === false) {
            throw new RuntimeException('API-Key konnte nicht gespeichert werden.');
        }

        chmod($this->credentialFile, 0600);
    }

    /**
     * @return string
     */
    private function loadOrCreateKey(): string
    {
        if (is_file($this->keyFile)) {
            $key = file_get_contents($this->keyFile);

            if ($key !== false && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                return $key;
            }
        }

        $directory = dirname($this->keyFile);

        if ($directory !== '' && !is_dir($directory)) {
            if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new RuntimeException('Schlüsselverzeichnis konnte nicht erstellt werden: ' . $directory);
            }
        }

        $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

        if (file_put_contents($this->keyFile, $key, LOCK_EX) === false) {
            throw new RuntimeException('Schlüssel konnte nicht gespeichert werden.');
        }

        chmod($this->keyFile, 0600);

        return $key;
    }
}
