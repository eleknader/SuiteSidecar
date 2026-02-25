<?php

declare(strict_types=1);

namespace SuiteSidecar;

use SuiteSidecar\SuiteCrm\Profile;
use SuiteSidecar\Http\Response;
use InvalidArgumentException;

final class ProfileResolver
{
    public function __construct(
        private readonly ProfileRegistry $profileRegistry
    ) {
    }

    public function resolve(array $query, array $headers): Profile
    {
        $hostProfile = $this->resolveHostProfile($headers);
        if ($hostProfile !== null) {
            $requestedProfileId = $this->extractProfileId($query, $headers);
            if ($requestedProfileId !== null && $requestedProfileId !== $hostProfile->id) {
                error_log(
                    '[requestId=' . Response::requestId() . '] Host-routed profile override applied: requestedProfileId='
                    . $requestedProfileId
                    . ' resolvedProfileId='
                    . $hostProfile->id
                );
            }
            return $hostProfile;
        }

        if ($this->isHostRoutingRequired()) {
            throw new ProfileResolutionException('Request host is not mapped to a profile');
        }

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

    public function resolveForLookup(array $query, array $headers): Profile
    {
        return $this->resolve($query, $headers);
    }

    public function assertHostRoutingSatisfied(array $headers): void
    {
        if (!$this->isHostRoutingRequired()) {
            return;
        }

        $hostProfile = $this->resolveHostProfile($headers);
        if ($hostProfile === null) {
            throw new ProfileResolutionException('Request host is not mapped to a profile');
        }
    }

    public function resolveHostProfile(array $headers): ?Profile
    {
        $requestHost = $this->extractRequestHost($headers);
        if ($requestHost === null) {
            return null;
        }

        try {
            return $this->profileRegistry->getByHost($requestHost);
        } catch (InvalidArgumentException $e) {
            throw new ProfileResolutionException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<Profile>
     */
    public function listProfilesForRequest(array $headers): array
    {
        $hostProfile = $this->resolveHostProfile($headers);
        if ($hostProfile !== null) {
            return [$hostProfile];
        }

        if ($this->isHostRoutingRequired()) {
            throw new ProfileResolutionException('Request host is not mapped to a profile');
        }

        return $this->profileRegistry->all();
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

    private function extractRequestHost(array $headers): ?string
    {
        $hostHeader = trim((string) $this->getHeaderValue($headers, 'Host'));
        $forwardedHost = trim((string) $this->getHeaderValue($headers, 'X-Forwarded-Host'));
        if ($this->shouldUseForwardedHost($forwardedHost)) {
            $hostHeader = $forwardedHost;
        }

        if ($hostHeader === '') {
            return null;
        }

        $host = Profile::normalizeHostForMatch($hostHeader);
        return $host !== '' ? $host : null;
    }

    private function shouldUseForwardedHost(string $forwardedHost): bool
    {
        if ($forwardedHost === '' || !$this->trustForwardedHost()) {
            return false;
        }

        $trustedProxySources = $this->trustedProxySources();
        if ($trustedProxySources === []) {
            error_log(
                '[requestId=' . Response::requestId()
                . '] Ignoring X-Forwarded-Host because SUITESIDECAR_TRUSTED_PROXY_IPS is empty'
            );
            return false;
        }

        $remoteAddr = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
        if ($remoteAddr === '') {
            error_log(
                '[requestId=' . Response::requestId()
                . '] Ignoring X-Forwarded-Host because REMOTE_ADDR is missing'
            );
            return false;
        }

        if ($this->isTrustedProxy($remoteAddr, $trustedProxySources)) {
            return true;
        }

        error_log(
            '[requestId=' . Response::requestId()
            . '] Ignoring X-Forwarded-Host from untrusted proxy source: remoteAddr='
            . $remoteAddr
        );
        return false;
    }

    private function trustForwardedHost(): bool
    {
        $raw = strtolower(trim((string) getenv('SUITESIDECAR_TRUST_X_FORWARDED_HOST')));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return array<string>
     */
    private function trustedProxySources(): array
    {
        $raw = (string) getenv('SUITESIDECAR_TRUSTED_PROXY_IPS');
        if (trim($raw) === '') {
            return [];
        }

        $parts = array_map(
            static fn (string $item): string => trim($item),
            explode(',', $raw)
        );

        $sources = [];
        foreach ($parts as $source) {
            if ($source === '') {
                continue;
            }

            if ($this->isValidTrustedProxySource($source)) {
                $sources[] = $source;
                continue;
            }

            error_log(
                '[requestId=' . Response::requestId()
                . '] Ignoring invalid trusted proxy entry in SUITESIDECAR_TRUSTED_PROXY_IPS: '
                . $source
            );
        }

        return array_values(array_unique($sources));
    }

    /**
     * @param array<string> $trustedSources
     */
    private function isTrustedProxy(string $remoteAddr, array $trustedSources): bool
    {
        foreach ($trustedSources as $source) {
            if (str_contains($source, '/')) {
                if ($this->isInCidr($remoteAddr, $source)) {
                    return true;
                }
                continue;
            }

            if ($remoteAddr === $source) {
                return true;
            }
        }

        return false;
    }

    private function isValidTrustedProxySource(string $source): bool
    {
        if (str_contains($source, '/')) {
            [$network, $prefixRaw] = array_pad(explode('/', $source, 2), 2, '');
            $network = trim($network);
            $prefixRaw = trim($prefixRaw);

            if ($network === '' || $prefixRaw === '' || !ctype_digit($prefixRaw)) {
                return false;
            }

            $isV4 = filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
            $isV6 = !$isV4 && filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
            if (!$isV4 && !$isV6) {
                return false;
            }

            $prefix = (int) $prefixRaw;
            return $prefix >= 0 && $prefix <= ($isV4 ? 32 : 128);
        }

        return filter_var($source, FILTER_VALIDATE_IP) !== false;
    }

    private function isInCidr(string $ip, string $cidr): bool
    {
        [$network, $prefixRaw] = array_pad(explode('/', $cidr, 2), 2, '');
        if ($network === '' || $prefixRaw === '') {
            return false;
        }

        $networkBin = @inet_pton($network);
        $ipBin = @inet_pton($ip);
        if ($networkBin === false || $ipBin === false || strlen($networkBin) !== strlen($ipBin)) {
            return false;
        }

        $prefix = (int) $prefixRaw;
        $maxPrefix = strlen($networkBin) * 8;
        if ($prefix < 0 || $prefix > $maxPrefix) {
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($fullBytes > 0 && substr($networkBin, 0, $fullBytes) !== substr($ipBin, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
        return (ord($networkBin[$fullBytes]) & $mask) === (ord($ipBin[$fullBytes]) & $mask);
    }

    private function isHostRoutingRequired(): bool
    {
        $envValue = getenv('SUITESIDECAR_REQUIRE_HOST_ROUTING');
        if ($envValue !== false && trim((string) $envValue) !== '') {
            return $this->toBool((string) $envValue);
        }

        // Auto-enable strict routing whenever host mappings are configured.
        return $this->profileRegistry->hasAnyHostMappings();
    }

    private function toBool(string $raw): bool
    {
        return in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true);
    }
}
