<?php

declare(strict_types=1);

namespace SuiteSidecar;

use SuiteSidecar\Http\Response;
use SuiteSidecar\SuiteCrm\Profile;
use Throwable;

final class ProfileController
{
    public function __construct(
        private readonly array $profiles
    ) {
    }

    public function listProfiles(): void
    {
        try {
            $publicProfiles = [];
            foreach ($this->profiles as $profile) {
                if (!$profile instanceof Profile) {
                    throw new \RuntimeException('Invalid profile type');
                }
                $publicProfiles[] = $profile->toPublicArray();
            }

            Response::json([
                'profiles' => $publicProfiles,
            ], 200);
        } catch (Throwable) {
            Response::error('server_error', 'Invalid profiles configuration', 500);
        }
    }
}
