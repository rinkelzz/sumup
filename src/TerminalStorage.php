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
    /**
     * @var string
     */
    private $file;

    public function __construct(string $file)
    {
        $initialContent = json_encode(['terminals' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($initialContent === false) {
            throw new RuntimeException('Die Terminaldaten konnten nicht initialisiert werden.');
        }

        $resolved = $this->prepareStorageFile($file, $initialContent, 'terminals.json');

        if ($resolved === null) {
            throw new RuntimeException('Die Terminaldaten konnten nicht persistiert werden. Bitte Schreibrechte für das Verzeichnis "var" vergeben, ein beschreibbares Verzeichnis über die Umgebungsvariable "SUMUP_STORAGE_DIR" angeben oder ein beschreibbares Temp-Verzeichnis bereitstellen.');
        }

        $this->file = $resolved;
    }

    /**
     * @return string|null
     */
    private function prepareStorageFile(string $preferredFile, string $initialContent, string $fallbackBasename)
    {
        foreach ($this->candidateFiles($preferredFile, $fallbackBasename) as $candidate) {
            if ($this->initialiseFile($candidate, $initialContent)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function candidateFiles(string $preferredFile, string $fallbackBasename): array
    {
        $candidates = [];

        if ($preferredFile !== '') {
            $candidates[] = $preferredFile;
        }

        $directories = [];
        $environmentDirectory = getenv('SUMUP_STORAGE_DIR');

        if (is_string($environmentDirectory) && $environmentDirectory !== '') {
            $directories[] = $environmentDirectory;
        }

        $projectRoot = dirname(__DIR__);
        $directories[] = $projectRoot . DIRECTORY_SEPARATOR . 'var';
        $directories[] = $projectRoot . DIRECTORY_SEPARATOR . 'tmp';
        $directories[] = $projectRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'data';
        $directories[] = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sumup-storage';

        foreach ($directories as $directory) {
            if (!is_string($directory) || $directory === '') {
                continue;
            }

            $normalized = rtrim($directory, DIRECTORY_SEPARATOR);

            if ($normalized === '') {
                continue;
            }

            $file = $normalized . DIRECTORY_SEPARATOR . $fallbackBasename;

            if (!in_array($file, $candidates, true)) {
                $candidates[] = $file;
            }
        }

        return $candidates;
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
            static function (array $terminal) use ($id): bool {
                return ($terminal['id'] ?? null) !== $id;
            }
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
        } catch (Throwable $exception) {
            return uniqid('terminal_', true);
        }
    }
}
