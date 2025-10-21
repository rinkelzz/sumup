<?php

declare(strict_types=1);

namespace App;

use RuntimeException;
use Throwable;

/**
 * Simple JSON-based storage for SumUp terminal configurations.
 */
final class TerminalStorage
{
    private string $file;

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
            $initialContent = json_encode(['terminals' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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
        $terminals = $data['terminals'] ?? [];

        return is_array($terminals) ? $terminals : [];
    }

    public function find(string $id): ?array
    {
        foreach ($this->all() as $terminal) {
            if (($terminal['id'] ?? null) === $id) {
                return $terminal;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $terminal
     * @return array<string, mixed>
     */
    public function add(array $terminal): array
    {
        $terminals = $this->all();

        if (!isset($terminal['id'])) {
            $terminal['id'] = $this->generateId();
        }

        $terminals[] = $terminal;
        $this->writeFile(['terminals' => $terminals]);

        return $terminal;
    }

    public function remove(string $id): void
    {
        $filtered = array_values(array_filter(
            $this->all(),
            static fn(array $terminal): bool => ($terminal['id'] ?? null) !== $id
        ));

        $this->writeFile(['terminals' => $filtered]);
    }

    /**
     * @return array<string, mixed>
     */
    private function readFile(): array
    {
        $contents = file_get_contents($this->file);

        if ($contents === false || $contents === '') {
            return ['terminals' => []];
        }

        $decoded = json_decode($contents, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        throw new RuntimeException('Die Terminaldatei enthält ungültige JSON-Daten.');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeFile(array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new RuntimeException('Die Terminaldaten konnten nicht serialisiert werden.');
        }

        if (file_put_contents($this->file, $json) === false) {
            throw new RuntimeException(sprintf('Die Terminaldaten konnten nicht nach %s geschrieben werden.', $this->file));
        }
    }

    private function generateId(): string
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (Throwable) {
            return uniqid('terminal_', true);
        }
    }
}
