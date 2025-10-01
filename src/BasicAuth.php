<?php

declare(strict_types=1);

namespace SumUp;

final class BasicAuth
{
    /**
     * @param array{realm?: string, users?: array<string, string>} $authConfig
     * @return string authenticated username
     */
    public static function enforce(array $authConfig): string
    {
        $realm = isset($authConfig['realm']) ? (string) $authConfig['realm'] : 'SumUp Terminal';
        $users = $authConfig['users'] ?? [];

        if ($users === []) {
            self::renderHtmlError('Keine Benutzer für den Zugriff konfiguriert. Bitte ergänzen Sie config/config.php.', 500);
        }

        $authenticated = false;
        $username = '';

        if (isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
            $username = (string) $_SERVER['PHP_AUTH_USER'];
            $password = (string) $_SERVER['PHP_AUTH_PW'];

            if (array_key_exists($username, $users)) {
                $storedHash = (string) $users[$username];
                $authenticated = password_verify($password, $storedHash)
                    || hash_equals($storedHash, $password);
            }
        }

        if ($authenticated === false) {
            header('WWW-Authenticate: Basic realm="' . addslashes($realm) . '", charset="UTF-8"');
            self::renderHtmlError('Authentifizierung erforderlich.', 401);
        }

        return $username;
    }

    private static function renderHtmlError(string $message, int $statusCode): void
    {
        http_response_code($statusCode);
        header('Content-Type: text/html; charset=UTF-8');

        $safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        echo <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Zugriff geschützt</title>
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
            background: #0f172a;
            color: #f9fafb;
            padding: 2rem;
        }

        .card {
            background: #111827;
            border-radius: 1rem;
            padding: 2.5rem 2rem;
            max-width: 32rem;
            text-align: center;
            box-shadow: 0 1.5rem 3rem rgba(15, 23, 42, 0.35);
        }

        h1 {
            margin-top: 0;
            margin-bottom: 1rem;
            font-size: 1.75rem;
        }

        p {
            margin: 0;
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>Zugriff verweigert</h1>
        <p>{$safeMessage}</p>
    </div>
</body>
</html>
HTML;

        exit;
    }
}
