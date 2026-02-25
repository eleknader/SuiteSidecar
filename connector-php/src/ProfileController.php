<?php

declare(strict_types=1);

namespace SuiteSidecar;

use SuiteSidecar\Http\Response;
use Throwable;

final class ProfileController
{
    public function __construct(
        private readonly ProfileResolver $profileResolver
    ) {
    }

    public function listProfiles(array $headers = []): void
    {
        try {
            $publicProfiles = [];
            $profiles = $this->profileResolver->listProfilesForRequest($headers);
            foreach ($profiles as $profile) {
                $publicProfiles[] = $profile->toPublicArray();
            }

            Response::json([
                'profiles' => $publicProfiles,
            ], 200);
        } catch (ProfileResolutionException $e) {
            Response::error('bad_request', $e->getMessage(), 400);
        } catch (Throwable) {
            Response::error('server_error', 'Invalid profiles configuration', 500);
        }
    }
}
