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

        return $registry;
    }
}
