<?php

declare(strict_types=1);

use SumUp\BasicAuth;
use SumUp\CredentialStore;

require_once __DIR__ . '/../src/BasicAuth.php';
require_once __DIR__ . '/../src/CredentialStore.php';

$configPath = __DIR__ . '/../config/config.php';

if (!file_exists($configPath)) {
    http_response_code(500);
    echo 'Konfigurationsdatei nicht gefunden. Bitte kopieren Sie config/config.example.php nach config/config.php.';
    exit;
}

/**
 * @var array{
 *     auth?: array{realm?: string, users?: array<string, string>},
 *     secure_store?: array{credential_file?: string, key_file?: string}
 * } $config
 */
$config = require $configPath;

$authConfig = $config['auth'] ?? [];
$authenticatedUser = BasicAuth::enforce($authConfig);

$secureStoreConfig = $config['secure_store'] ?? [];
$store = null;
$storeError = null;
$successMessage = null;
$errorMessage = null;
$storedCredential = null;

if (!isset($secureStoreConfig['credential_file'], $secureStoreConfig['key_file'])) {
    $storeError = 'In der Konfiguration fehlen die Pfade für die sichere Ablage (secure_store.credential_file/key_file).';
} else {
    try {
        $store = new CredentialStore(
            (string) $secureStoreConfig['credential_file'],
            (string) $secureStoreConfig['key_file']
        );
        $storedCredential = $store->getApiCredential();
    } catch (Throwable $exception) {
        $storeError = $exception->getMessage();
    }
}

if ($store instanceof CredentialStore && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? (string) $_POST['action'] : 'save';

    if ($action === 'clear') {
        $store->clear();
        $storedCredential = null;
        $successMessage = 'Der gespeicherte API-Key wurde entfernt.';
    } else {
        $merchantId = isset($_POST['merchant_id']) ? trim((string) $_POST['merchant_id']) : '';
        $apiKey = isset($_POST['api_key']) ? (string) $_POST['api_key'] : '';

        try {
            $store->saveApiKey($merchantId, $apiKey);
            $storedCredential = $store->getApiCredential();
            $successMessage = 'API-Key wurde sicher gespeichert und steht jetzt in der Anwendung zur Verfügung.';
        } catch (Throwable $exception) {
            $errorMessage = $exception->getMessage();
        }
    }
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>SumUp API-Key hinterlegen</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            color-scheme: light dark;
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
        }

        body {
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: #f4f4f8;
        }

        .panel {
            background: #ffffff;
            padding: 2.5rem 2rem;
            border-radius: 1rem;
            box-shadow: 0 1.5rem 3rem rgba(15, 23, 42, 0.1);
            max-width: 36rem;
            width: 92%;
        }

        h1 {
            margin-top: 0;
            color: #111827;
            font-size: 1.75rem;
        }

        p {
            color: #4b5563;
            line-height: 1.6;
        }

        form {
            display: grid;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        label {
            display: flex;
            flex-direction: column;
            font-weight: 600;
            color: #374151;
        }

        input {
            margin-top: 0.4rem;
            padding: 0.8rem 1rem;
            border-radius: 0.75rem;
            border: 1px solid #d1d5db;
            font-size: 1rem;
        }

        button {
            background: #2563eb;
            color: #fff;
            border: none;
            border-radius: 999px;
            padding: 0.9rem 1.5rem;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s ease-in-out;
        }

        button:hover,
        button:focus {
            background: #1d4ed8;
        }

        .button-danger {
            background: #dc2626;
        }

        .button-danger:hover,
        .button-danger:focus {
            background: #b91c1c;
        }

        .alert {
            border-radius: 0.75rem;
            padding: 1rem 1.25rem;
            margin-bottom: 1rem;
        }

        .alert.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .alert.success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .alert.info {
            background: #bfdbfe;
            color: #1e3a8a;
            border: 1px solid #93c5fd;
        }

        .actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .actions form {
            margin-top: 0;
        }

        .muted {
            color: #6b7280;
            font-size: 0.9rem;
        }

        @media (prefers-color-scheme: dark) {
            body {
                background: #0f172a;
            }

            .panel {
                background: #111827;
                color: #f9fafb;
                box-shadow: none;
                border: 1px solid #1f2937;
            }

            h1 {
                color: #f3f4f6;
            }

            p,
            label,
            .muted {
                color: #d1d5db;
            }

            input {
                background: #1f2937;
                color: #f9fafb;
                border: 1px solid #374151;
            }

            .alert.success {
                background: rgba(22, 101, 52, 0.2);
                border-color: rgba(22, 101, 52, 0.4);
            }

            .alert.error {
                background: rgba(185, 28, 28, 0.25);
                border-color: rgba(185, 28, 28, 0.4);
                color: #fecaca;
            }

            .alert.info {
                background: rgba(30, 64, 175, 0.2);
                border-color: rgba(30, 64, 175, 0.4);
                color: #bfdbfe;
            }

            .button-danger {
                background: #dc2626;
            }

            .button-danger:hover,
            .button-danger:focus {
                background: #b91c1c;
            }
        }

        a {
            color: #2563eb;
        }
    </style>
</head>
<body>
<div class="panel">
    <h1>API-Key sicher hinterlegen</h1>

    <p>
        Melden Sie sich in Ihrem SumUp-Händlerkonto unter <a href="https://me.sumup.com/developers" target="_blank" rel="noreferrer noopener">developers</a> an, kopieren Sie dort den API-Key (Personal Access Token) und fügen Sie ihn unten ein.
        Die Anwendung verschlüsselt den Schlüssel lokal und nutzt ihn anschließend automatisch für Terminal-Zahlungen.
    </p>

    <p class="muted">Angemeldet als <?= escape($authenticatedUser) ?></p>

    <?php if ($storeError !== null): ?>
        <div class="alert error">
            <?= escape($storeError) ?>
        </div>
    <?php endif; ?>

    <?php if ($successMessage !== null): ?>
        <div class="alert success">
            <?= escape($successMessage) ?>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage !== null): ?>
        <div class="alert error">
            <?= escape($errorMessage) ?>
        </div>
    <?php endif; ?>

    <?php if ($store instanceof CredentialStore): ?>
        <form method="post">
            <label>
                Händler-E-Mail oder Referenz (optional)
                <input type="text" name="merchant_id" value="<?= $storedCredential !== null ? escape($storedCredential['merchant_id']) : '' ?>" placeholder="z. B. meine-filiale@example.com">
            </label>

            <label>
                SumUp API-Key
                <input type="password" name="api_key" autocomplete="off" placeholder="su_pk_..." required>
            </label>

            <button type="submit" name="action" value="save">API-Key speichern</button>
        </form>

        <?php if ($storedCredential !== null): ?>
            <div class="alert info" style="margin-top: 1.5rem;">
                <strong>Aktuell gespeicherter Schlüssel</strong>
                <p>
                    <?= $storedCredential['merchant_id'] !== '' ? 'Händler: ' . escape($storedCredential['merchant_id']) . '<br>' : '' ?>
                    Zuletzt aktualisiert: <?= isset($storedCredential['updated_at']) ? escape($storedCredential['updated_at']) : 'unbekannt' ?>
                </p>
                <div class="actions">
                    <form method="post" onsubmit="return confirm('Gespeicherten API-Key wirklich löschen?');">
                        <input type="hidden" name="action" value="clear">
                        <button type="submit" class="button-danger">API-Key löschen</button>
                    </form>
                    <a href="index.php">Zurück zur Kasse</a>
                </div>
            </div>
        <?php else: ?>
            <p style="margin-top: 1.5rem;">
                Nach dem Speichern steht der Schlüssel automatisch in <a href="index.php">index.php</a> zur Verfügung.
            </p>
        <?php endif; ?>
    <?php else: ?>
        <p class="alert error">Sichere Ablage ist nicht verfügbar. Bitte beheben Sie die oben genannten Probleme.</p>
    <?php endif; ?>
</div>
</body>
</html>
