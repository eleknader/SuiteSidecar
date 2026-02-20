<?php

declare(strict_types=1);

namespace SuiteSidecar;

use SuiteSidecar\Http\Response;

final class ProfileController
{
    public function listProfiles(): void
    {
        $configFile = dirname(__DIR__) . '/config/profiles.php';

        if (!is_file($configFile)) {
            Response::error('server_error', 'Profiles configuration file is missing', 500);
            return;
        }

        $profiles = require $configFile;

        if (!is_array($profiles)) {
            Response::error('server_error', 'Invalid profiles configuration', 500);
            return;
        }

        $normalizedProfiles = [];
        foreach ($profiles as $profile) {
            if (
                !is_array($profile)
                || !isset($profile['id'], $profile['name'], $profile['suitecrmBaseUrl'], $profile['apiFlavor'])
            ) {
                Response::error('server_error', 'Invalid profiles configuration item', 500);
                return;
            }

            $normalizedProfiles[] = [
                'id' => (string) $profile['id'],
                'name' => (string) $profile['name'],
                'suitecrmBaseUrl' => (string) $profile['suitecrmBaseUrl'],
                'apiFlavor' => (string) $profile['apiFlavor'],
                'notes' => isset($profile['notes']) ? (string) $profile['notes'] : null,
            ];
        }

        Response::json([
            'profiles' => $normalizedProfiles,
        ], 200);
    }
}
