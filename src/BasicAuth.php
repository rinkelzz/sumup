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
            http_response_code(500);
            echo 'Keine Benutzer für den Zugriff konfiguriert. Bitte ergänzen Sie config/config.php.';
            exit;
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
            http_response_code(401);
            echo 'Authentifizierung erforderlich.';
            exit;
        }

        return $username;
    }
}
