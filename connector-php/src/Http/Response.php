<?php

declare(strict_types=1);

namespace SuiteSidecar\Http;

final class Response
{
    private static ?string $requestId = null;
    private static ?string $resolvedHost = null;
    private static ?string $resolvedProfileId = null;

    public static function requestId(): string
    {
        if (self::$requestId === null) {
            self::$requestId = bin2hex(random_bytes(8));
        }
        return self::$requestId;
    }

    public static function json(array $data, int $status = 200): void
    {
        http_response_code($status);

        // Provide requestId also as header for easier troubleshooting
        header('X-Request-Id: ' . self::requestId());
        self::applyRoutingHeaders();

        if (!array_key_exists('requestId', $data)) {
            $data['requestId'] = self::requestId();
        }

        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function error(string $code, string $message, int $status, ?array $details = null): void
    {
        $error = [
            'code' => $code,
            'message' => $message,
            'requestId' => self::requestId(),
        ];
        if (is_array($details) && $details !== []) {
            $error['details'] = $details;
        }

        self::json([
            'error' => $error,
        ], $status);
    }

    public static function setRoutingContext(?string $resolvedHost, ?string $resolvedProfileId): void
    {
        self::$resolvedHost = self::normalizeHeaderValue($resolvedHost);
        self::$resolvedProfileId = self::normalizeHeaderValue($resolvedProfileId);
    }

    public static function setResolvedProfileId(?string $resolvedProfileId): void
    {
        self::$resolvedProfileId = self::normalizeHeaderValue($resolvedProfileId);
    }

    private static function applyRoutingHeaders(): void
    {
        if (self::$resolvedHost !== null) {
            header('X-SuiteSidecar-Resolved-Host: ' . self::$resolvedHost);
        }

        if (self::$resolvedProfileId !== null) {
            header('X-SuiteSidecar-Resolved-Profile: ' . self::$resolvedProfileId);
        }
    }

    private static function normalizeHeaderValue(?string $value): ?string
    {
        $normalized = trim((string) $value);
        if ($normalized === '' || preg_match('/[\r\n]/', $normalized) === 1) {
            return null;
        }

        return $normalized;
    }
}
