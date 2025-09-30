<?php

declare(strict_types=1);

return [
    'sumup' => [
        // Generate a personal access token via https://me.sumup.com/developers
        'access_token' => 'your-access-token-here',
        // Default currency in ISO 4217 format
        'currency' => 'EUR',
        // Configure one or multiple SumUp terminals that can receive payments from the web UI
        'terminals' => [
            [
                'serial' => 'ABCDEF123456',
                'label' => 'Tresen',
            ],
            [
                'serial' => 'GHIJKL987654',
                'label' => 'Terrasse',
            ],
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
];
