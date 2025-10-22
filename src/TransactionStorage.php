<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;

/**
 * Stores incoming SumUp webhook payloads as JSON files.
 */
final class TransactionStorage
{
    /**
     * @var string
     */
    private $directory;

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, '/');

        if (!is_dir($this->directory)) {
            if (!mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
                throw new RuntimeException(sprintf('Das Verzeichnis %s konnte nicht erstellt werden.', $this->directory));
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function append(string $transactionId, array $payload): void
    {
        $file = $this->buildFilePath($transactionId);
        $data = $this->readFile($file, $transactionId);

        if (!isset($data['events']) || !is_array($data['events'])) {
            $data['events'] = [];
        }

        $data['events'][] = $payload;
        $data['last_updated'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

        $this->writeFile($file, $data);
    }

    private function buildFilePath(string $transactionId): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9_\-]/', '_', $transactionId);

        if ($sanitized === null || $sanitized === '') {
            throw new RuntimeException('Die Transaktions-ID ist ungültig.');
        }

        return $this->directory . '/' . $sanitized . '.json';
    }

    /**
     * @return array<string, mixed>
     */
    private function readFile(string $file, string $transactionId): array
    {
        if (!file_exists($file)) {
            return [
                'id' => $transactionId,
                'events' => [],
                'created_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            ];
        }

        $contents = file_get_contents($file);

        if ($contents === false || $contents === '') {
            return [
                'id' => $transactionId,
                'events' => [],
                'created_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            ];
        }

        $decoded = json_decode($contents, true);

        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Die Transaktionsdatei %s enthält ungültige JSON-Daten.', $file));
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeFile(string $file, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new RuntimeException('Die Transaktionsdaten konnten nicht serialisiert werden.');
        }

        if (file_put_contents($file, $json) === false) {
            throw new RuntimeException(sprintf('Die Transaktionsdaten konnten nicht nach %s geschrieben werden.', $file));
        }
    }
}
