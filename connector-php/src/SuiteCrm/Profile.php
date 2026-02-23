<?php

declare(strict_types=1);

namespace SuiteSidecar\SuiteCrm;

use InvalidArgumentException;

final class Profile
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $suitecrmBaseUrl,
        public readonly string $apiFlavor,
        public readonly string $oauthTokenUrl,
        public readonly string $oauthClientId,
        public readonly string $oauthClientSecret,
        public readonly string $oauthGrantType,
        public readonly ?string $notes = null,
    ) {
    }

    public static function loadAll(string $configFile): array
    {
        if (!is_file($configFile)) {
            throw new InvalidArgumentException('Profiles configuration file is missing');
        }

        $profiles = require $configFile;
        if (!is_array($profiles)) {
            throw new InvalidArgumentException('Invalid profiles configuration');
        }

        $result = [];
        foreach ($profiles as $profile) {
            if (!is_array($profile)) {
                throw new InvalidArgumentException('Invalid profile entry in configuration');
            }
            $result[] = self::fromArray($profile);
        }

        return $result;
    }

    public static function fromArray(array $profile): self
    {
        foreach (['id', 'name', 'suitecrmBaseUrl', 'apiFlavor'] as $requiredField) {
            if (!isset($profile[$requiredField]) || trim((string) $profile[$requiredField]) === '') {
                throw new InvalidArgumentException('Missing required profile field: ' . $requiredField);
            }
        }

        $id = (string) $profile['id'];
        $name = (string) $profile['name'];
        $suitecrmBaseUrl = rtrim((string) $profile['suitecrmBaseUrl'], '/');
        if ($suitecrmBaseUrl === '') {
            throw new InvalidArgumentException('Invalid suitecrmBaseUrl for profile: ' . $id);
        }

        $apiFlavor = (string) $profile['apiFlavor'];
        $notes = isset($profile['notes']) ? (string) $profile['notes'] : null;

        $oauth = [];
        if (isset($profile['oauth'])) {
            if (!is_array($profile['oauth'])) {
                throw new InvalidArgumentException('Invalid oauth config for profile: ' . $id);
            }
            $oauth = $profile['oauth'];
        }

        $tokenUrl = isset($oauth['tokenUrl']) && trim((string) $oauth['tokenUrl']) !== ''
            ? (string) $oauth['tokenUrl']
            : $suitecrmBaseUrl . '/legacy/Api/access_token';
        $clientId = isset($oauth['clientId']) ? (string) $oauth['clientId'] : '';
        $clientSecret = isset($oauth['clientSecret']) ? (string) $oauth['clientSecret'] : '';
        $grantType = isset($oauth['grantType']) && trim((string) $oauth['grantType']) !== ''
            ? (string) $oauth['grantType']
            : 'client_credentials';

        $normalizedProfileId = self::normalizeProfileIdForEnv($id);
        $envClientId = getenv('SUITESIDECAR_' . $normalizedProfileId . '_CLIENT_ID');
        if ($envClientId !== false && trim($envClientId) !== '') {
            $clientId = $envClientId;
        }

        $envClientSecret = getenv('SUITESIDECAR_' . $normalizedProfileId . '_CLIENT_SECRET');
        if ($envClientSecret !== false && trim($envClientSecret) !== '') {
            $clientSecret = $envClientSecret;
        }

        return new self(
            id: $id,
            name: $name,
            suitecrmBaseUrl: $suitecrmBaseUrl,
            apiFlavor: $apiFlavor,
            oauthTokenUrl: $tokenUrl,
            oauthClientId: $clientId,
            oauthClientSecret: $clientSecret,
            oauthGrantType: $grantType,
            notes: $notes,
        );
    }

    public static function normalizeProfileIdForEnv(string $profileId): string
    {
        $normalized = preg_replace('/[^A-Z0-9]+/', '_', strtoupper($profileId));
        $normalized = trim((string) $normalized, '_');
        return $normalized !== '' ? $normalized : 'PROFILE';
    }

    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'suitecrmBaseUrl' => $this->suitecrmBaseUrl,
            'apiFlavor' => $this->apiFlavor,
            'notes' => $this->notes,
        ];
    }

    public function deepLink(string $module, string $id): string
    {
        $moduleSegment = strtolower(trim($module));
        $recordId = rawurlencode(trim($id));

        return $this->suitecrmBaseUrl . '/#/' . $moduleSegment . '/record/' . $recordId;
    }

    public function legacyCreateLink(string $module, array $query = []): string
    {
        return $this->legacyActionLink($module, 'EditView', $query);
    }

    public function legacyListLink(string $module, array $query = []): string
    {
        return $this->legacyActionLink($module, 'index', $query);
    }

    public function legacyActionLink(string $module, string $action, array $query = []): string
    {
        $moduleName = trim($module);
        $actionName = trim($action);
        if ($moduleName === '') {
            throw new InvalidArgumentException('Module is required for legacy links');
        }
        if ($actionName === '') {
            throw new InvalidArgumentException('Action is required for legacy links');
        }

        $params = array_filter(
            array_merge(
                [
                    'module' => $moduleName,
                    'action' => $actionName,
                ],
                $query
            ),
            static fn (mixed $value): bool => $value !== null && trim((string) $value) !== ''
        );

        return $this->suitecrmBaseUrl . '/legacy/index.php?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}
