<?php

// This is an example configuration. Do NOT commit real credentials.
declare(strict_types=1);

return [
    [
        'id' => 'example-dev',
        'name' => 'Example CRM DEV',
        'suitecrmBaseUrl' => 'https://crm.example.com',
        'apiFlavor' => 'suitecrm_v8_jsonapi',
        'oauth' => [
            'tokenUrl' => 'https://crm.example.com/legacy/Api/access_token',
            'clientId' => 'your-client-id',
            'clientSecret' => 'your-client-secret',
            'grantType' => 'client_credentials',
        ],
    ],
];
