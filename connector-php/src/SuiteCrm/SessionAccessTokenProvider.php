<?php

declare(strict_types=1);

namespace SuiteSidecar\SuiteCrm;

final class SessionAccessTokenProvider implements AccessTokenProviderInterface
{
    public function __construct(
        private readonly array $session,
        private readonly string $requestId,
    ) {
    }

    public function getAccessToken(Profile $profile): string
    {
        $sessionProfileId = isset($this->session['profileId']) ? (string) $this->session['profileId'] : '';
        if ($sessionProfileId !== $profile->id) {
            error_log('[requestId=' . $this->requestId . '] Session profile mismatch');
            throw new SuiteCrmAuthException('Session profile mismatch', 401);
        }

        $accessToken = isset($this->session['suitecrmAccessToken']) ? (string) $this->session['suitecrmAccessToken'] : '';
        if ($accessToken === '') {
            error_log('[requestId=' . $this->requestId . '] Session access token is missing');
            throw new SuiteCrmAuthException('SuiteCRM access token is missing', 401);
        }

        $expiresAt = isset($this->session['suitecrmTokenExpiresAt']) ? (int) $this->session['suitecrmTokenExpiresAt'] : 0;
        if ($expiresAt > 0 && $expiresAt <= (time() + 30)) {
            error_log('[requestId=' . $this->requestId . '] Session access token has expired');
            throw new SuiteCrmAuthException('SuiteCRM access token has expired', 401);
        }

        return $accessToken;
    }
}
