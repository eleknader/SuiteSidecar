<?php

declare(strict_types=1);

namespace SuiteSidecar\Http;

final class Response
{
    private static ?string $requestId = null;

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
}
