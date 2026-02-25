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
        /** @var array<string> */
        public readonly array $hosts = [],
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
        $hosts = self::normalizeHosts($profile['hosts'] ?? []);

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
            hosts: $hosts,
            notes: $notes,
        );
    }

    private static function normalizeHosts(mixed $rawHosts): array
    {
        if ($rawHosts === null || $rawHosts === []) {
            return [];
        }

        if (!is_array($rawHosts)) {
            throw new InvalidArgumentException('Invalid hosts config in profile; expected array');
        }

        $normalizedHosts = [];
        foreach ($rawHosts as $value) {
            $host = self::normalizeHostPattern((string) $value);
            if ($host !== '') {
                $normalizedHosts[] = $host;
            }
        }

        return array_values(array_unique($normalizedHosts));
    }

    private static function normalizeHostPattern(string $value): string
    {
        $candidate = strtolower(trim($value));
        if ($candidate === '') {
            return '';
        }

        $wildcardPrefix = '';
        if (str_starts_with($candidate, '*.')) {
            $wildcardPrefix = '*.';
            $candidate = substr($candidate, 2);
        }

        $candidate = preg_replace('/:\d+$/', '', $candidate);
        $candidate = rtrim((string) $candidate, '.');

        if ($candidate === '') {
            throw new InvalidArgumentException('Invalid host pattern in profile hosts list');
        }

        if (!self::isValidHostValue($candidate)) {
            throw new InvalidArgumentException('Invalid host pattern in profile hosts list');
        }

        if (
            $wildcardPrefix !== ''
            && (
                str_contains($candidate, '.') === false
                || self::isIpAddress($candidate)
                || $candidate === 'localhost'
            )
        ) {
            throw new InvalidArgumentException('Wildcard host pattern must target a domain suffix');
        }

        return $wildcardPrefix . $candidate;
    }

    public function matchesHost(string $requestHost): bool
    {
        if ($this->hosts === []) {
            return false;
        }

        $host = self::normalizeHostForMatch($requestHost);
        if ($host === '') {
            return false;
        }

        foreach ($this->hosts as $pattern) {
            if (str_starts_with($pattern, '*.')) {
                $suffix = substr($pattern, 1);
                $apex = substr($pattern, 2);
                if ($apex !== '' && $host !== $apex && str_ends_with($host, (string) $suffix)) {
                    return true;
                }
                continue;
            }

            if ($host === $pattern) {
                return true;
            }
        }

        return false;
    }

    public static function normalizeHostForMatch(string $value): string
    {
        $host = strtolower(trim($value));
        if ($host === '') {
            return '';
        }

        if (str_contains($host, ',')) {
            $parts = explode(',', $host);
            $host = trim((string) ($parts[0] ?? ''));
        }

        if (str_starts_with($host, '[') && str_contains($host, ']')) {
            $end = strpos($host, ']');
            if ($end !== false) {
                $host = substr($host, 1, $end - 1);
            }
        } else {
            $host = preg_replace('/:\d+$/', '', $host);
        }

        $host = rtrim((string) $host, '.');
        if ($host === '' || !self::isValidHostValue($host)) {
            return '';
        }

        return $host;
    }

    private static function isValidHostValue(string $host): bool
    {
        if ($host === 'localhost') {
            return true;
        }

        if (self::isIpAddress($host)) {
            return true;
        }

        if (strlen($host) > 253) {
            return false;
        }

        $labels = explode('.', $host);
        foreach ($labels as $label) {
            if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
                return false;
            }
        }

        return true;
    }

    private static function isIpAddress(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP) !== false;
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
