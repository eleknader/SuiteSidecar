<?php

declare(strict_types=1);

namespace SuiteSidecar;

final class RuntimeLimits
{
    private const ATTACHMENT_SAFETY_RATIO = 0.70;

    public static function resolve(): array
    {
        $phpPostMaxBytes = self::parseByteSize(ini_get('post_max_size'));
        $phpUploadMaxFileSizeBytes = self::parseByteSize(ini_get('upload_max_filesize'));

        // Use the tighter PHP transport limit by default for request envelope checks.
        $phpTransportLimitBytes = self::minPositiveInt($phpPostMaxBytes, $phpUploadMaxFileSizeBytes);
        $envMaxRequestBytes = self::toPositiveIntOrNull(getenv('SUITESIDECAR_MAX_REQUEST_BYTES'));
        $maxRequestBytes = self::minPositiveInt($phpTransportLimitBytes, $envMaxRequestBytes);

        $recommendedAttachmentBytes = $maxRequestBytes !== null
            ? self::toPositiveIntOrNull((string) floor($maxRequestBytes * self::ATTACHMENT_SAFETY_RATIO))
            : null;
        $envMaxAttachmentBytes = self::toPositiveIntOrNull(getenv('SUITESIDECAR_MAX_ATTACHMENT_BYTES'));
        $maxAttachmentBytes = self::minPositiveInt($recommendedAttachmentBytes, $envMaxAttachmentBytes);

        return [
            'phpPostMaxBytes' => $phpPostMaxBytes,
            'phpUploadMaxFileSizeBytes' => $phpUploadMaxFileSizeBytes,
            'maxRequestBytes' => $maxRequestBytes,
            'recommendedAttachmentBytes' => $recommendedAttachmentBytes,
            'maxAttachmentBytes' => $maxAttachmentBytes,
        ];
    }

    public static function currentContentLengthBytes(): ?int
    {
        $value = $_SERVER['CONTENT_LENGTH'] ?? null;
        if ($value === null) {
            return null;
        }

        return self::toPositiveIntOrNull((string) $value);
    }

    private static function minPositiveInt(?int $a, ?int $b): ?int
    {
        if ($a !== null && $b !== null) {
            return min($a, $b);
        }

        if ($a !== null) {
            return $a;
        }

        if ($b !== null) {
            return $b;
        }

        return null;
    }

    private static function toPositiveIntOrNull(mixed $value): ?int
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        if (!preg_match('/^[0-9]+$/', $raw)) {
            return null;
        }

        $number = (int) $raw;
        return $number > 0 ? $number : null;
    }

    private static function parseByteSize(mixed $value): ?int
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '' || $raw === '-1') {
            return null;
        }

        if (!preg_match('/^([0-9]+)([KMGTP]?)$/i', $raw, $matches)) {
            return null;
        }

        $amount = (int) $matches[1];
        if ($amount <= 0) {
            return null;
        }

        $suffix = strtoupper($matches[2]);
        $power = match ($suffix) {
            'K' => 1,
            'M' => 2,
            'G' => 3,
            'T' => 4,
            'P' => 5,
            default => 0,
        };

        return $amount * (1024 ** $power);
    }
}
