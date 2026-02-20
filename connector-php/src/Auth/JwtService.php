<?php

declare(strict_types=1);

namespace SuiteSidecar\Auth;

final class JwtService
{
    public function __construct(
        private readonly string $secret,
        private readonly int $ttlSeconds = 28800,
    ) {
        if (trim($this->secret) === '') {
            throw new AuthException('JWT secret is missing');
        }
    }

    public function issueToken(string $subjectId, string $profileId, string $username, ?string $email): array
    {
        $issuedAt = time();
        $expiresAt = $issuedAt + max(60, $this->ttlSeconds);

        $header = [
            'typ' => 'JWT',
            'alg' => 'HS256',
        ];
        $payload = [
            'sub' => $subjectId,
            'profileId' => $profileId,
            'username' => $username,
            'email' => $email,
            'iat' => $issuedAt,
            'exp' => $expiresAt,
        ];

        $encodedHeader = $this->base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $encodedPayload = $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signingInput = $encodedHeader . '.' . $encodedPayload;
        $signature = hash_hmac('sha256', $signingInput, $this->secret, true);

        return [
            'token' => $signingInput . '.' . $this->base64UrlEncode($signature),
            'expiresAt' => $expiresAt,
        ];
    }

    public function validateToken(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new AuthException('Invalid token format');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $headerJson = $this->base64UrlDecode($encodedHeader);
        $payloadJson = $this->base64UrlDecode($encodedPayload);
        if ($headerJson === '' || $payloadJson === '') {
            throw new AuthException('Invalid token encoding');
        }

        $header = json_decode($headerJson, true);
        $payload = json_decode($payloadJson, true);
        if (!is_array($header) || !is_array($payload)) {
            throw new AuthException('Invalid token payload');
        }

        if (($header['alg'] ?? '') !== 'HS256') {
            throw new AuthException('Unsupported token algorithm');
        }

        $expectedSignature = hash_hmac('sha256', $encodedHeader . '.' . $encodedPayload, $this->secret, true);
        $providedSignature = $this->base64UrlDecodeRaw($encodedSignature);
        if (!hash_equals($expectedSignature, $providedSignature)) {
            throw new AuthException('Invalid token signature');
        }

        $expiresAt = isset($payload['exp']) ? (int) $payload['exp'] : 0;
        if ($expiresAt <= time()) {
            throw new AuthException('Token has expired');
        }

        return $payload;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        return $decoded === false ? '' : $decoded;
    }

    private function base64UrlDecodeRaw(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        if ($decoded === false) {
            throw new AuthException('Invalid token signature encoding');
        }
        return $decoded;
    }
}
