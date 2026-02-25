<?php

declare(strict_types=1);

namespace SuiteSidecar\Http;

final class CorsPolicy
{
    /** @var array<string, bool> */
    private array $allowedOrigins;

    private function __construct(
        private readonly bool $allowAnyOrigin,
        array $allowedOrigins
    ) {
        $this->allowedOrigins = $allowedOrigins;
    }

    public static function fromEnvironment(): self
    {
        $rawAllowedOrigins = trim((string) getenv('SUITESIDECAR_ALLOWED_ORIGINS'));
        if ($rawAllowedOrigins === '') {
            return new self(true, []);
        }

        $allowedOrigins = [];
        foreach (explode(',', $rawAllowedOrigins) as $item) {
            $origin = self::normalizeOrigin($item);
            if ($origin === null) {
                $value = trim($item);
                if ($value !== '') {
                    error_log(
                        '[requestId=' . Response::requestId()
                        . '] Ignoring invalid origin in SUITESIDECAR_ALLOWED_ORIGINS: '
                        . $value
                    );
                }
                continue;
            }

            $allowedOrigins[$origin] = true;
        }

        if ($allowedOrigins === []) {
            error_log(
                '[requestId=' . Response::requestId()
                . '] Startup warning: SUITESIDECAR_ALLOWED_ORIGINS is set but has no valid origins; '
                . 'all browser-originated requests with Origin header will be rejected'
            );
        }

        return new self(false, $allowedOrigins);
    }

    /**
     * @return array{allowed: bool, allowOrigin: ?string, reason?: string}
     */
    public function evaluate(?string $originHeader): array
    {
        if ($this->allowAnyOrigin) {
            return [
                'allowed' => true,
                'allowOrigin' => '*',
            ];
        }

        $originRaw = trim((string) $originHeader);
        if ($originRaw === '') {
            return [
                'allowed' => true,
                'allowOrigin' => null,
            ];
        }

        $origin = self::normalizeOrigin($originRaw);
        if ($origin === null) {
            return [
                'allowed' => false,
                'allowOrigin' => null,
                'reason' => 'invalid_origin',
            ];
        }

        if (!isset($this->allowedOrigins[$origin])) {
            return [
                'allowed' => false,
                'allowOrigin' => null,
                'reason' => 'origin_not_allowed',
            ];
        }

        return [
            'allowed' => true,
            'allowOrigin' => $origin,
        ];
    }

    public function applyHeaders(?string $allowOrigin): void
    {
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-SuiteSidecar-Profile');
        header('Access-Control-Expose-Headers: X-Request-Id');
        header('Access-Control-Max-Age: 600');

        if ($allowOrigin === '*') {
            header('Access-Control-Allow-Origin: *');
            return;
        }

        header('Vary: Origin');
        if ($allowOrigin !== null) {
            header('Access-Control-Allow-Origin: ' . $allowOrigin);
        }
    }

    public static function applySecurityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
    }

    private static function normalizeOrigin(string $origin): ?string
    {
        $trimmed = trim($origin);
        if ($trimmed === '' || $trimmed === 'null') {
            return null;
        }

        $parts = parse_url($trimmed);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        $port = $parts['port'] ?? null;
        if ($port !== null) {
            $port = (int) $port;
            if ($port < 1 || $port > 65535) {
                return null;
            }
        }

        $defaultPort = $scheme === 'https' ? 443 : 80;
        $originValue = $scheme . '://' . $host;
        if ($port !== null && $port !== $defaultPort) {
            $originValue .= ':' . $port;
        }

        return $originValue;
    }
}
