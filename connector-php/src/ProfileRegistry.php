<?php

declare(strict_types=1);

namespace SuiteSidecar;

use SuiteSidecar\SuiteCrm\Profile;
use InvalidArgumentException;

final class ProfileRegistry
{
    /** @var array<string, Profile> */
    private array $profilesById = [];

    /**
     * @return array<Profile>
     */
    public function all(): array
    {
        return array_values($this->profilesById);
    }

    public function count(): int
    {
        return count($this->profilesById);
    }

    public function getById(string $profileId): ?Profile
    {
        return $this->profilesById[$profileId] ?? null;
    }

    public function hasAnyHostMappings(): bool
    {
        foreach ($this->profilesById as $profile) {
            if ($profile->hosts !== []) {
                return true;
            }
        }

        return false;
    }

    public function getByHost(string $requestHost): ?Profile
    {
        $host = Profile::normalizeHostForMatch($requestHost);
        if ($host === '') {
            return null;
        }

        $matched = [];
        foreach ($this->profilesById as $profile) {
            if ($profile->matchesHost($host)) {
                $matched[] = $profile;
            }
        }

        if ($matched === []) {
            return null;
        }

        if (count($matched) > 1) {
            $matchedIds = array_map(static fn (Profile $profile): string => $profile->id, $matched);
            throw new InvalidArgumentException(
                'Multiple profiles match request host "' . $host . '": ' . implode(', ', $matchedIds)
            );
        }

        return $matched[0];
    }

    public static function loadDefault(): self
    {
        $configDir = dirname(__DIR__) . '/config';
        $localConfig = $configDir . '/profiles.php';
        $exampleConfig = $configDir . '/profiles.example.php';

        if (is_file($localConfig)) {
            return self::fromFile($localConfig);
        }

        if (is_file($exampleConfig)) {
            return self::fromFile($exampleConfig);
        }

        throw new InvalidArgumentException('Profiles configuration file is missing');
    }

    public static function fromFile(string $configFile): self
    {
        $profiles = Profile::loadAll($configFile);
        $registry = new self();

        foreach ($profiles as $profile) {
            if (isset($registry->profilesById[$profile->id])) {
                throw new InvalidArgumentException('Duplicate profile id: ' . $profile->id);
            }
            $registry->profilesById[$profile->id] = $profile;
        }

        $registry->validateHostMappings();

        return $registry;
    }

    private function validateHostMappings(): void
    {
        /** @var array<string, array<string>> $exactHosts */
        $exactHosts = [];
        /** @var array<array{profileId: string, pattern: string, suffix: string}> $wildcards */
        $wildcards = [];

        foreach ($this->profilesById as $profile) {
            foreach ($profile->hosts as $pattern) {
                if (str_starts_with($pattern, '*.')) {
                    $wildcards[] = [
                        'profileId' => $profile->id,
                        'pattern' => $pattern,
                        'suffix' => substr($pattern, 2),
                    ];
                    continue;
                }

                $exactHosts[$pattern][] = $profile->id;
            }
        }

        foreach ($exactHosts as $host => $profileIds) {
            $uniqueProfileIds = array_values(array_unique($profileIds));
            if (count($uniqueProfileIds) > 1) {
                throw new InvalidArgumentException(
                    'Host mapping "' . $host . '" is assigned to multiple profiles: '
                    . implode(', ', $uniqueProfileIds)
                );
            }
        }

        foreach ($exactHosts as $host => $profileIds) {
            $exactProfileId = $profileIds[0];
            foreach ($wildcards as $wildcard) {
                if ($wildcard['profileId'] === $exactProfileId) {
                    continue;
                }

                if ($this->hostMatchesWildcard($host, $wildcard['suffix'])) {
                    throw new InvalidArgumentException(
                        'Ambiguous host mappings: exact host "' . $host . '" (profile '
                        . $exactProfileId
                        . ') overlaps wildcard "'
                        . $wildcard['pattern']
                        . '" (profile '
                        . $wildcard['profileId']
                        . ')'
                    );
                }
            }
        }

        $wildcardCount = count($wildcards);
        for ($left = 0; $left < $wildcardCount; $left++) {
            for ($right = $left + 1; $right < $wildcardCount; $right++) {
                $a = $wildcards[$left];
                $b = $wildcards[$right];
                if ($a['profileId'] === $b['profileId']) {
                    continue;
                }

                if ($this->wildcardsOverlap($a['suffix'], $b['suffix'])) {
                    throw new InvalidArgumentException(
                        'Ambiguous host mappings: wildcard "'
                        . $a['pattern']
                        . '" (profile '
                        . $a['profileId']
                        . ') overlaps wildcard "'
                        . $b['pattern']
                        . '" (profile '
                        . $b['profileId']
                        . ')'
                    );
                }
            }
        }
    }

    private function hostMatchesWildcard(string $host, string $suffix): bool
    {
        return $host !== $suffix && str_ends_with($host, '.' . $suffix);
    }

    private function wildcardsOverlap(string $suffixA, string $suffixB): bool
    {
        if ($suffixA === $suffixB) {
            return true;
        }

        return str_ends_with($suffixA, '.' . $suffixB) || str_ends_with($suffixB, '.' . $suffixA);
    }
}
