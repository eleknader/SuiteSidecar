<?php

declare(strict_types=1);

namespace SuiteSidecar\Auth;

final class SessionStore
{
    public function __construct(
        private readonly string $sessionsDir = __DIR__ . '/../../var/sessions'
    ) {
    }

    public function save(string $subjectId, array $session): void
    {
        if (!is_dir($this->sessionsDir) && !mkdir($concurrentDirectory = $this->sessionsDir, 0770, true) && !is_dir($concurrentDirectory)) {
            throw new AuthException('Unable to create sessions directory');
        }

        $payload = json_encode($session, JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new AuthException('Unable to serialize session');
        }

        $bytesWritten = file_put_contents($this->sessionFile($subjectId), $payload, LOCK_EX);
        if ($bytesWritten === false) {
            throw new AuthException('Unable to write session');
        }
    }

    public function load(string $subjectId): ?array
    {
        $sessionFile = $this->sessionFile($subjectId);
        if (!is_file($sessionFile)) {
            return null;
        }

        $raw = file_get_contents($sessionFile);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function delete(string $subjectId): void
    {
        $sessionFile = $this->sessionFile($subjectId);
        if (!is_file($sessionFile)) {
            return;
        }

        if (!unlink($sessionFile)) {
            throw new AuthException('Unable to delete session');
        }
    }

    private function sessionFile(string $subjectId): string
    {
        $safeSubject = preg_replace('/[^A-Za-z0-9._-]+/', '_', $subjectId);
        return rtrim($this->sessionsDir, '/') . '/' . $safeSubject . '.json';
    }
}
