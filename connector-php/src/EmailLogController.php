<?php

declare(strict_types=1);

namespace SuiteSidecar;

use SuiteSidecar\Http\Response;
use SuiteSidecar\SuiteCrm\Profile;

final class EmailLogController
{
    private const ALLOWED_LINK_MODULES = ['Contacts', 'Leads', 'Accounts', 'Opportunities', 'Cases'];

    public function log(Profile $profile): void
    {
        $payload = $this->readJsonBody();
        if ($payload === null) {
            Response::error('bad_request', 'Invalid JSON body', 400);
            return;
        }

        $message = isset($payload['message']) && is_array($payload['message']) ? $payload['message'] : null;
        $linkTo = isset($payload['linkTo']) && is_array($payload['linkTo']) ? $payload['linkTo'] : null;
        if ($message === null || $linkTo === null) {
            Response::error('bad_request', 'Missing required fields: message, linkTo', 400);
            return;
        }

        $internetMessageId = trim((string) ($message['internetMessageId'] ?? ''));
        $subject = trim((string) ($message['subject'] ?? ''));
        $sentAt = trim((string) ($message['sentAt'] ?? ''));
        $from = isset($message['from']) && is_array($message['from']) ? $message['from'] : [];
        $to = isset($message['to']) && is_array($message['to']) ? $message['to'] : [];

        if ($internetMessageId === '' || $subject === '' || $sentAt === '') {
            Response::error('bad_request', 'Missing required message fields', 400);
            return;
        }

        if ($this->parseDateTime($sentAt) === null) {
            Response::error('bad_request', 'Invalid sentAt datetime format', 400);
            return;
        }

        $fromEmail = trim((string) ($from['email'] ?? ''));
        if (!$this->isValidEmail($fromEmail)) {
            Response::error('bad_request', 'Invalid from.email', 400);
            return;
        }

        if ($to === []) {
            Response::error('bad_request', 'Missing required message recipients: to', 400);
            return;
        }

        foreach ($to as $recipient) {
            $recipientEmail = is_array($recipient) ? trim((string) ($recipient['email'] ?? '')) : '';
            if (!$this->isValidEmail($recipientEmail)) {
                Response::error('bad_request', 'Invalid recipient email in message.to', 400);
                return;
            }
        }

        $module = trim((string) ($linkTo['module'] ?? ''));
        $linkId = trim((string) ($linkTo['id'] ?? ''));
        if ($module === '' || $linkId === '') {
            Response::error('bad_request', 'Missing required linkTo fields', 400);
            return;
        }

        if (!in_array($module, self::ALLOWED_LINK_MODULES, true)) {
            Response::error('bad_request', 'Invalid linkTo.module', 400);
            return;
        }

        $recordId = bin2hex(random_bytes(16));
        $displayName = substr($subject, 0, 180);

        error_log(
            '[requestId=' . Response::requestId() . '] Email log accepted'
            . ' profileId=' . $profile->id
            . ' linkModule=' . $module
            . ' linkId=' . $linkId
            . ' messageId=' . $internetMessageId
        );

        Response::json([
            'loggedRecord' => [
                'module' => 'Emails',
                'id' => $recordId,
                'displayName' => $displayName,
                'link' => $profile->deepLink('Emails', $recordId),
            ],
            'deduplicated' => false,
        ], 201);
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

    private function parseDateTime(string $value): ?int
    {
        $timestamp = strtotime($value);
        return $timestamp === false ? null : $timestamp;
    }

    private function isValidEmail(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }
}
