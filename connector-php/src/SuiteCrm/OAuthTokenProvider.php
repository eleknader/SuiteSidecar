<?php

declare(strict_types=1);

namespace SuiteSidecar\SuiteCrm;

final class OAuthTokenProvider
{
    private const MIN_TTL_SECONDS = 30;
    private const DEFAULT_EXPIRES_IN = 300;

    private array $memoryCache = [];
    private string $requestId = '-';

    public function __construct(
        private readonly string $cacheDir = __DIR__ . '/../../var/tokens'
    ) {
    }

    public function setRequestId(string $requestId): void
    {
        $this->requestId = $requestId;
    }

    public function getAccessToken(Profile $profile): string
    {
        if ($profile->oauthGrantType !== 'client_credentials') {
            throw new SuiteCrmAuthException('Unsupported OAuth grant type');
        }

        $cachedToken = $this->loadFromMemoryCache($profile->id);
        if ($cachedToken !== null) {
            return $cachedToken;
        }

        $cachedToken = $this->loadFromFileCache($profile->id);
        if ($cachedToken !== null) {
            return $cachedToken;
        }

        if ($profile->oauthClientId === '' || $profile->oauthClientSecret === '') {
            $this->log('Token fetch failed: missing client credentials for profile ' . $profile->id);
            throw new SuiteCrmAuthException('Missing OAuth client credentials');
        }

        $formData = http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $profile->oauthClientId,
            'client_secret' => $profile->oauthClientSecret,
        ], '', '&', PHP_QUERY_RFC3986);

        $ch = curl_init($profile->oauthTokenUrl);
        if ($ch === false) {
            throw new SuiteCrmAuthException('Failed to initialize token request');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $formData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
                'User-Agent: suitesidecar-connector-php/0.1.0',
            ],
        ]);

        $rawResponse = curl_exec($ch);
        if ($rawResponse === false) {
            $curlError = curl_error($ch);
            curl_close($ch);
            $this->log('Token fetch failed for profile ' . $profile->id . ': transport error');
            throw new SuiteCrmAuthException('Unable to reach SuiteCRM token endpoint: ' . $curlError);
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($statusCode >= 400) {
            $this->log(
                'Token fetch failed for profile ' . $profile->id
                . ': HTTP ' . $statusCode
                . ' body="' . $this->bodySnippet((string) $rawResponse) . '"'
            );
            throw new SuiteCrmAuthException('SuiteCRM token endpoint rejected the request');
        }

        $decoded = json_decode((string) $rawResponse, true);
        if (!is_array($decoded)) {
            $this->log('Token fetch failed for profile ' . $profile->id . ': invalid JSON response');
            throw new SuiteCrmAuthException('SuiteCRM token endpoint returned invalid JSON');
        }

        $accessToken = $decoded['access_token'] ?? null;
        if (!is_string($accessToken) || $accessToken === '') {
            $this->log('Token fetch failed for profile ' . $profile->id . ': access_token missing');
            throw new SuiteCrmAuthException('SuiteCRM token endpoint did not return access_token');
        }

        $expiresIn = isset($decoded['expires_in']) ? (int) $decoded['expires_in'] : self::DEFAULT_EXPIRES_IN;
        if ($expiresIn <= 0) {
            $expiresIn = self::DEFAULT_EXPIRES_IN;
        }

        $expiresAt = time() + $expiresIn;
        $this->memoryCache[$profile->id] = [
            'accessToken' => $accessToken,
            'expiresAt' => $expiresAt,
        ];
        $this->saveToFileCache($profile->id, $accessToken, $expiresAt);

        return $accessToken;
    }

    private function loadFromMemoryCache(string $profileId): ?string
    {
        if (!isset($this->memoryCache[$profileId])) {
            return null;
        }

        $token = $this->memoryCache[$profileId];
        if (!is_array($token) || ($token['expiresAt'] ?? 0) <= (time() + self::MIN_TTL_SECONDS)) {
            return null;
        }

        return isset($token['accessToken']) && is_string($token['accessToken']) ? $token['accessToken'] : null;
    }

    private function loadFromFileCache(string $profileId): ?string
    {
        $cacheFile = $this->cacheFile($profileId);
        if (!is_file($cacheFile)) {
            return null;
        }

        $raw = file_get_contents($cacheFile);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $accessToken = $decoded['accessToken'] ?? null;
        $expiresAt = isset($decoded['expiresAt']) ? (int) $decoded['expiresAt'] : 0;

        if (!is_string($accessToken) || $accessToken === '' || $expiresAt <= (time() + self::MIN_TTL_SECONDS)) {
            return null;
        }

        $this->memoryCache[$profileId] = [
            'accessToken' => $accessToken,
            'expiresAt' => $expiresAt,
        ];

        return $accessToken;
    }

    private function saveToFileCache(string $profileId, string $accessToken, int $expiresAt): void
    {
        if (!is_dir($this->cacheDir) && !mkdir($concurrentDirectory = $this->cacheDir, 0770, true) && !is_dir($concurrentDirectory)) {
            $this->log('Failed to create token cache directory: ' . $this->cacheDir);
            return;
        }

        $payload = json_encode([
            'accessToken' => $accessToken,
            'expiresAt' => $expiresAt,
        ], JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            $this->log('Failed to serialize token cache payload for profile ' . $profileId);
            return;
        }

        file_put_contents($this->cacheFile($profileId), $payload, LOCK_EX);
    }

    private function cacheFile(string $profileId): string
    {
        $safeProfileId = preg_replace('/[^A-Za-z0-9._-]+/', '_', $profileId);
        return rtrim($this->cacheDir, '/') . '/' . $safeProfileId . '.json';
    }

    private function bodySnippet(string $body): string
    {
        return substr(preg_replace('/\s+/', ' ', trim($body)) ?? '', 0, 200);
    }

    private function log(string $message): void
    {
        error_log('[requestId=' . $this->requestId . '] ' . $message);
    }
}
