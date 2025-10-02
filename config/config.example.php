<?php

declare(strict_types=1);

return [
    'sumup' => [
        // Choose whether you authenticate with an API key (own account) or an OAuth access token (multi-merchant platforms)
        'auth_method' => 'api_key', // allowed values: api_key, oauth
        // Leave blank when you store the key via public/anmeldung.php. Use the secret key (prefix "sum_sk_").
        'api_key' => '',
        // Optional but recommended for API-key setups: SumUp merchant code (e.g. MCRNF79M) used for terminal discovery
        'merchant_code' => '',
        // For OAuth set auth_method to "oauth" and place the access token below instead
        'access_token' => 'your-oauth-access-token-here',
        // Default currency in ISO 4217 format
        'currency' => 'EUR',
        // Configure one or multiple SumUp terminals that can receive payments from the web UI
        // You can either provide a single serial as string, a numerically indexed list as below or use the serial number as array key
        'terminals' => [
            [
                'serial' => 'ABCDEF123456',
                'label' => 'Tresen',
            ],
            [
                'serial' => 'GHIJKL987654',
                'label' => 'Terrasse',
            ],
            // 'ABCDEF123456' => [
            //     'label' => 'Tresen',
            // ],
            // 'GHIJKL987654' => 'Terrasse',
        ],
        // Optional fallback for legacy single terminal setups:
        // 'terminal_serial' => 'ABCDEF123456',
        // 'terminal_label' => 'Tresen',
    ],
    'auth' => [
        // Browser will display this name when prompting for credentials
        'realm' => 'SumUp Terminal',
        // username => password-hash pairs (use `password_hash` to generate new hashes)
        'users' => [
            'kasse' => '$2y$10$n9DYLqWc1jBJUniH1IpR/OYlfCfPfkKnSVYju3MrnaqwTfKRAK5wi', // password: change-me
        ],
    ],
    'log' => [
        // Absolute or relative path where transaction attempts will be appended (will be created automatically)
        'transactions_file' => __DIR__ . '/../var/transactions.log',
    ],
    'secure_store' => [
        // Files used by the encrypted credential store (not committed to Git)
        'credential_file' => __DIR__ . '/../var/sumup_credentials.json',
        'key_file' => __DIR__ . '/../var/secure_store.key',
    ],
];
