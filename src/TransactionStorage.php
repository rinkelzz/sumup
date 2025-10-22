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
        $initialContent = json_encode(['transactions' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($initialContent === false) {
            throw new RuntimeException('Die Transaktionsdaten konnten nicht initialisiert werden.');
        }

        $resolved = $this->prepareStorageFile($file, $initialContent, 'transactions.json');

        if ($resolved === null) {
            throw new RuntimeException('Die Transaktionsdaten konnten nicht persistiert werden. Bitte Schreibrechte für das Verzeichnis "var" vergeben oder ein beschreibbares Temp-Verzeichnis bereitstellen.');
        }

        $this->file = $resolved;
    }

    /**
     * @return string|null
     */
    private function prepareStorageFile(string $preferredFile, string $initialContent, string $fallbackBasename)
    {
        if ($this->initialiseFile($preferredFile, $initialContent)) {
            return $preferredFile;
        }

        $tempDirectory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sumup-storage';
        $fallbackFile = $tempDirectory . DIRECTORY_SEPARATOR . $fallbackBasename;

        if ($this->initialiseFile($fallbackFile, $initialContent)) {
            return $fallbackFile;
        }

        return null;
    }

    private function initialiseFile(string $file, string $initialContent): bool
    {
        $directory = dirname($file);

        if (!$this->ensureDirectory($directory)) {
            return false;
        }

        if (!file_exists($file)) {
            if (@file_put_contents($file, $initialContent, LOCK_EX) === false) {
                return false;
            }
        }

        if (!is_writable($file) && !@chmod($file, 0664)) {
            return false;
        }

        return is_writable($file);
    }

    private function ensureDirectory(string $directory): bool
    {
        if (is_dir($directory)) {
            if (is_writable($directory)) {
                return true;
            }

            return @chmod($directory, 0775) && is_writable($directory);
        }

        return @mkdir($directory, 0775, true) || is_dir($directory);
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
