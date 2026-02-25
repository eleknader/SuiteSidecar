<?php

// This is an example configuration. Do NOT commit real credentials.
declare(strict_types=1);

return [
    [
        'id' => 'example-dev',
        'name' => 'Example CRM DEV',
        'suitecrmBaseUrl' => 'https://crm.example.com',
        'apiFlavor' => 'suitecrm_v8_jsonapi',
        // Optional host routing: when request Host matches one of these,
        // this profile is auto-selected and profileId can be omitted.
        'hosts' => [
            'dev.suitesidecar.example.com',
            '*.tenant-dev.example.com',
        ],
        'oauth' => [
            'tokenUrl' => 'https://crm.example.com/legacy/Api/access_token',
            // Keep credentials in env vars, not in this file.
            'clientId' => '',
            'clientSecret' => '',
            'grantType' => 'client_credentials',
        ],
    ],
];
