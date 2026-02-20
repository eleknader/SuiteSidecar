<?php

declare(strict_types=1);

namespace SuiteSidecar;

use SuiteSidecar\SuiteCrm\Profile;

final class ProfileResolver
{
    public function __construct(
        private readonly ProfileRegistry $profileRegistry
    ) {
    }

    public function resolveForLookup(array $query, array $headers): Profile
    {
        $profileId = $this->extractProfileId($query, $headers);
        if ($profileId === null) {
            if ($this->profileRegistry->count() === 1) {
                return $this->profileRegistry->all()[0];
            }
            throw new ProfileResolutionException('Missing profileId');
        }

        $profile = $this->profileRegistry->getById($profileId);
        if ($profile === null) {
            throw new ProfileResolutionException('Unknown profileId');
        }

        return $profile;
    }

    private function extractProfileId(array $query, array $headers): ?string
    {
        $queryProfileId = isset($query['profileId']) ? trim((string) $query['profileId']) : '';
        if ($queryProfileId !== '') {
            return $queryProfileId;
        }

        $headerProfileId = trim((string) $this->getHeaderValue($headers, 'X-SuiteSidecar-Profile'));
        if ($headerProfileId !== '') {
            return $headerProfileId;
        }

        return null;
    }

    private function getHeaderValue(array $headers, string $name): ?string
    {
        foreach ($headers as $headerName => $value) {
            if (strcasecmp((string) $headerName, $name) === 0) {
                return is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
            }
        }
        return null;
    }
}
