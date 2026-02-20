<?php

declare(strict_types=1);

namespace SuiteSidecar\Auth;

use SuiteSidecar\Http\Response;

final class AuthMiddleware
{
    public function __construct(
        private readonly JwtService $jwtService,
        private readonly SessionStore $sessionStore,
    ) {
    }

    public function requireAuth(array $headers): ?array
    {
        $authorizationHeader = $this->getHeaderValue($headers, 'Authorization');
        if ($authorizationHeader === null || !preg_match('/^Bearer\s+(.+)$/i', trim($authorizationHeader), $matches)) {
            Response::error('unauthorized', 'Missing or invalid Authorization header', 401);
            return null;
        }

        $token = trim((string) ($matches[1] ?? ''));
        if ($token === '') {
            Response::error('unauthorized', 'Missing or invalid Authorization header', 401);
            return null;
        }

        try {
            $claims = $this->jwtService->validateToken($token);
        } catch (AuthException) {
            Response::error('unauthorized', 'Invalid or expired token', 401);
            return null;
        }

        $subjectId = isset($claims['sub']) ? (string) $claims['sub'] : '';
        if ($subjectId === '') {
            Response::error('unauthorized', 'Invalid token subject', 401);
            return null;
        }

        $session = $this->sessionStore->load($subjectId);
        if ($session === null) {
            Response::error('unauthorized', 'Session not found', 401);
            return null;
        }

        return [
            'claims' => $claims,
            'session' => $session,
        ];
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
