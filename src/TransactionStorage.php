<?php

declare(strict_types=1);

namespace App;

use RuntimeException;
use Throwable;

/**
 * Persisted storage for SumUp checkout responses.
 */
final class TransactionStorage
{
    /**
     * @var string
     */
    private $file;

    public function __construct(string $file)
    {
        $this->file = $file;
        $directory = dirname($file);

        if (!is_dir($directory)) {
            if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException(sprintf('Das Verzeichnis %s konnte nicht erstellt werden.', $directory));
            }
        }

        if (!file_exists($file)) {
            $initialContent = json_encode(['transactions' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            if ($initialContent === false || file_put_contents($file, $initialContent) === false) {
                throw new RuntimeException(sprintf('Die Datei %s konnte nicht angelegt werden.', $file));
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $data = $this->readFile();
        $transactions = $data['transactions'] ?? [];

        return is_array($transactions) ? $transactions : [];
    }

    /**
     * @param array<string, mixed> $transaction
     * @return array<string, mixed>
     */
    public function add(array $transaction): array
    {
        $transactions = $this->all();

        if (!isset($transaction['id'])) {
            $transaction['id'] = $this->generateId();
        }

        $transactions[] = $transaction;
        $this->writeFile(['transactions' => $transactions]);

        return $transaction;
    }

    /**
     * @return array<string, mixed>
     */
    private function readFile(): array
    {
        $contents = file_get_contents($this->file);

        if ($contents === false || $contents === '') {
            return ['transactions' => []];
        }

        $decoded = json_decode($contents, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        throw new RuntimeException('Die Transaktionsdatei enthält ungültige JSON-Daten.');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeFile(array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new RuntimeException('Die Transaktionsdaten konnten nicht serialisiert werden.');
        }

        if (file_put_contents($this->file, $json) === false) {
            throw new RuntimeException(sprintf('Die Transaktionsdaten konnten nicht nach %s geschrieben werden.', $this->file));
        }
    }

    private function generateId(): string
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (Throwable $exception) {
            return uniqid('transaction_', true);
        }
    }
}
