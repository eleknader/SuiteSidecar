<?php

declare(strict_types=1);

namespace SuiteSidecar\Auth;

use SuiteSidecar\Http\Response;
use SuiteSidecar\ProfileRegistry;
use SuiteSidecar\ProfileResolver;
use SuiteSidecar\ProfileResolutionException;
use SuiteSidecar\SuiteCrm\OAuthTokenProvider;
use SuiteSidecar\SuiteCrm\SuiteCrmAuthException;

final class AuthController
{
    public function __construct(
        private readonly ProfileRegistry $profileRegistry,
        private readonly ProfileResolver $profileResolver,
        private readonly OAuthTokenProvider $oauthTokenProvider,
        private readonly JwtService $jwtService,
        private readonly SessionStore $sessionStore,
    ) {
    }

    public function login(array $headers = []): void
    {
        try {
            $this->profileResolver->assertHostRoutingSatisfied($headers);
        } catch (ProfileResolutionException $e) {
            Response::error('bad_request', $e->getMessage(), 400);
            return;
        }

        $payload = $this->readJsonBody();
        if ($payload === null) {
            Response::error('bad_request', 'Invalid JSON body', 400);
            return;
        }

        $profileId = trim((string) ($payload['profileId'] ?? ''));
        $username = trim((string) ($payload['username'] ?? ''));
        $password = (string) ($payload['password'] ?? '');

        if ($username === '' || $password === '') {
            Response::error('bad_request', 'Missing required login fields', 400);
            return;
        }

        $hostProfile = $this->profileResolver->resolveHostProfile($headers);
        if ($hostProfile !== null) {
            if ($profileId !== '' && $profileId !== $hostProfile->id) {
                error_log(
                    '[requestId=' . Response::requestId() . '] Host-routed login override applied:'
                    . ' requestedProfileId=' . $profileId
                    . ' resolvedProfileId=' . $hostProfile->id
                );
            }
            $profile = $hostProfile;
        } elseif ($profileId === '') {
            if ($this->profileRegistry->count() === 1) {
                $profiles = $this->profileRegistry->all();
                $profile = $profiles[0];
            } else {
                Response::error('bad_request', 'Missing profileId', 400);
                return;
            }
        } else {
            $profile = $this->profileRegistry->getById($profileId);
            if ($profile === null) {
                Response::error('bad_request', 'Unknown profileId', 400);
                return;
            }
        }

        if ($profile->apiFlavor !== 'suitecrm_v8_jsonapi') {
            Response::error('bad_request', 'Unsupported profile apiFlavor', 400);
            return;
        }

        Response::setResolvedProfileId($profile->id);

        $this->oauthTokenProvider->setRequestId(Response::requestId());

        try {
            $suiteCrmTokens = $this->oauthTokenProvider->loginWithPasswordGrant($profile, $username, $password);
        } catch (SuiteCrmAuthException $e) {
            error_log('[requestId=' . Response::requestId() . '] SuiteCRM auth failed for login');
            Response::error('suitecrm_auth_failed', 'SuiteCRM authentication failed', $e->getStatusCode());
            return;
        }

        $subjectId = bin2hex(random_bytes(16));
        $email = filter_var($username, FILTER_VALIDATE_EMAIL) ? $username : null;
        $jwtData = $this->jwtService->issueToken($subjectId, $profile->id, $username, $email);

        try {
            $this->sessionStore->save($subjectId, [
                'subjectId' => $subjectId,
                'profileId' => $profile->id,
                'username' => $username,
                'email' => $email,
                'suitecrmAccessToken' => $suiteCrmTokens['accessToken'],
                'suitecrmRefreshToken' => $suiteCrmTokens['refreshToken'],
                'suitecrmTokenExpiresAt' => $suiteCrmTokens['expiresAt'],
                'createdAt' => time(),
            ]);
        } catch (AuthException) {
            error_log('[requestId=' . Response::requestId() . '] Failed to persist auth session');
            Response::error('server_error', 'Unable to create session', 500);
            return;
        }

        Response::json([
            'token' => $jwtData['token'],
            'tokenExpiresAt' => gmdate('c', (int) $jwtData['expiresAt']),
            'profileId' => $profile->id,
            'user' => [
                'id' => $subjectId,
                'displayName' => $username,
                'email' => $email,
            ],
        ], 200);
    }

    private function readJsonBody(): ?array
    {
        $rawBody = file_get_contents('php://input');
        if (!is_string($rawBody) || trim($rawBody) === '') {
            return null;
        }

        $decoded = json_decode($rawBody, true);
        return is_array($decoded) ? $decoded : null;
    }
}
