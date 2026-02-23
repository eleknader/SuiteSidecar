<?php

declare(strict_types=1);

namespace SuiteSidecar;

final class EmailActionLogStore
{
    private string $baseDir;

    public function __construct(?string $baseDir = null)
    {
        $this->baseDir = $baseDir !== null && trim($baseDir) !== ''
            ? rtrim($baseDir, '/')
            : dirname(__DIR__) . '/var/email-action-log';
    }

    public function findTaskByMessageKeys(
        string $profileId,
        ?string $graphMessageId,
        ?string $internetMessageId
    ): ?array {
        $normalizedProfileId = $this->normalizeProfileId($profileId);
        if ($normalizedProfileId === '') {
            return null;
        }

        $keys = $this->normalizeKeys($graphMessageId, $internetMessageId);
        foreach ($keys as $keyType => $keyValue) {
            if ($keyValue === null) {
                continue;
            }

            $entry = $this->readEntry($normalizedProfileId, $keyType, $keyValue);
            if ($entry !== null) {
                return $entry;
            }
        }

        return null;
    }

    public function saveTaskMessageKeys(
        string $profileId,
        ?string $graphMessageId,
        ?string $internetMessageId,
        array $task,
        array $audit
    ): void {
        $normalizedProfileId = $this->normalizeProfileId($profileId);
        if ($normalizedProfileId === '') {
            return;
        }

        $taskId = trim((string) ($task['id'] ?? ''));
        if ($taskId === '') {
            return;
        }

        $keys = $this->normalizeKeys($graphMessageId, $internetMessageId);
        if ($keys['graph'] === null && $keys['internet'] === null) {
            return;
        }

        $entry = [
            'profileId' => $normalizedProfileId,
            'message' => [
                'graphMessageId' => $keys['graph'],
                'internetMessageId' => $keys['internet'],
            ],
            'task' => [
                'id' => $taskId,
                'link' => isset($task['link']) ? (string) $task['link'] : null,
                'displayName' => isset($task['displayName']) ? (string) $task['displayName'] : null,
            ],
            'audit' => [
                'createdAt' => isset($audit['createdAt']) ? (string) $audit['createdAt'] : gmdate('c'),
                'createdBy' => isset($audit['createdBy']) ? (string) $audit['createdBy'] : null,
                'createdBySubjectId' => isset($audit['createdBySubjectId']) ? (string) $audit['createdBySubjectId'] : null,
                'fromEmail' => isset($audit['fromEmail']) ? (string) $audit['fromEmail'] : null,
            ],
        ];

        foreach ($keys as $keyType => $keyValue) {
            if ($keyValue === null) {
                continue;
            }
            $this->writeEntry($normalizedProfileId, $keyType, $keyValue, $entry);
        }
    }

    private function normalizeKeys(?string $graphMessageId, ?string $internetMessageId): array
    {
        return [
            'graph' => $this->normalizeKey($graphMessageId, false),
            'internet' => $this->normalizeKey($internetMessageId, true),
        ];
    }

    private function normalizeKey(?string $value, bool $stripAngleBrackets): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        if ($stripAngleBrackets) {
            $normalized = preg_replace('/\s+/', '', strtolower($normalized)) ?? '';
            $normalized = trim($normalized, '<>');
        }

        $normalized = trim($normalized);
        if ($normalized === '') {
            return null;
        }

        return substr($normalized, 0, 255);
    }

    private function normalizeProfileId(string $profileId): string
    {
        $normalized = preg_replace('/[^a-zA-Z0-9._-]+/', '-', trim($profileId));
        return trim((string) $normalized, '-');
    }

    private function readEntry(string $profileId, string $keyType, string $keyValue): ?array
    {
        $path = $this->entryPath($profileId, $keyType, $keyValue);
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $task = isset($decoded['task']) && is_array($decoded['task']) ? $decoded['task'] : [];
        $taskId = trim((string) ($task['id'] ?? ''));
        if ($taskId === '') {
            return null;
        }

        return $decoded;
    }

    private function writeEntry(string $profileId, string $keyType, string $keyValue, array $entry): void
    {
        $dir = $this->baseDir . '/' . $profileId;
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }

        $path = $this->entryPath($profileId, $keyType, $keyValue);
        $encoded = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            return;
        }

        file_put_contents($path, $encoded, LOCK_EX);
    }

    private function entryPath(string $profileId, string $keyType, string $keyValue): string
    {
        $hash = hash('sha256', $keyType . ':' . $keyValue);
        return $this->baseDir . '/' . $profileId . '/task-' . $keyType . '-' . $hash . '.json';
    }
}
