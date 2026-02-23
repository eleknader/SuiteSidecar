<?php

declare(strict_types=1);

namespace SuiteSidecar;

use SuiteSidecar\Http\Response;
use SuiteSidecar\SuiteCrm\CrmAdapterInterface;
use SuiteSidecar\SuiteCrm\SuiteCrmAuthException;
use SuiteSidecar\SuiteCrm\SuiteCrmBadResponseException;
use SuiteSidecar\SuiteCrm\SuiteCrmException;
use SuiteSidecar\SuiteCrm\SuiteCrmHttpException;

final class TaskController
{
    private const ALLOWED_CONTEXT_MODULES = ['Contacts', 'Leads', 'Accounts'];

    public function __construct(
        private readonly CrmAdapterInterface $adapter
    ) {
    }

    public function createFromEmail(array $session, array $claims): void
    {
        $payload = $this->readJsonBody();
        if ($payload === null) {
            Response::error('bad_request', 'Invalid JSON body', 400);
            return;
        }

        $normalized = $this->normalizePayload($payload, $session, $claims);
        if ($normalized === null) {
            return;
        }

        try {
            $result = $this->adapter->createTaskFromEmail($normalized);
            $deduplicated = isset($result['deduplicated']) && $result['deduplicated'] === true;
            Response::json($result, $deduplicated ? 200 : 201);
        } catch (SuiteCrmAuthException $e) {
            error_log('[requestId=' . Response::requestId() . '] SuiteCRM auth failed during task create');
            $statusCode = $e->getStatusCode();
            if (!in_array($statusCode, [401, 502], true)) {
                $statusCode = 401;
            }
            Response::error('suitecrm_auth_failed', 'SuiteCRM authentication failed', $statusCode);
        } catch (SuiteCrmBadResponseException $e) {
            error_log('[requestId=' . Response::requestId() . '] Task create bad response: ' . $e->getMessage());
            Response::error('bad_request', 'Invalid task payload', 400);
        } catch (SuiteCrmHttpException $e) {
            error_log(
                '[requestId=' . Response::requestId() . '] SuiteCRM HTTP error'
                . ' endpoint=' . $e->getEndpoint()
                . ' status=' . $e->getStatus()
            );
            if (in_array($e->getStatus(), [401, 403], true)) {
                Response::error('suitecrm_auth_failed', 'SuiteCRM authentication failed', 401);
                return;
            }
            Response::error('suitecrm_unreachable', 'SuiteCRM is temporarily unreachable', 502);
        } catch (SuiteCrmException) {
            error_log('[requestId=' . Response::requestId() . '] SuiteCRM request failed during task create');
            Response::error('suitecrm_unreachable', 'SuiteCRM is temporarily unreachable', 502);
        }
    }

    private function normalizePayload(array $payload, array $session, array $claims): ?array
    {
        $message = isset($payload['message']) && is_array($payload['message']) ? $payload['message'] : null;
        if ($message === null) {
            Response::error('bad_request', 'Missing required field: message', 400);
            return null;
        }

        $from = isset($message['from']) && is_array($message['from']) ? $message['from'] : [];
        $fromEmail = trim((string) ($from['email'] ?? ''));
        if (!$this->isValidEmail($fromEmail)) {
            Response::error('bad_request', 'Invalid message.from.email', 400);
            return null;
        }

        $subject = trim((string) ($message['subject'] ?? ''));
        if ($subject === '') {
            Response::error('bad_request', 'Missing required field: message.subject', 400);
            return null;
        }

        $receivedDateTime = trim((string) ($message['receivedDateTime'] ?? ''));
        if ($receivedDateTime === '' || $this->parseDateTime($receivedDateTime) === null) {
            Response::error('bad_request', 'Invalid message.receivedDateTime', 400);
            return null;
        }

        $graphMessageId = $this->nullableString($message['graphMessageId'] ?? null);
        $internetMessageId = $this->nullableString($message['internetMessageId'] ?? null);
        if ($graphMessageId === null && $internetMessageId === null) {
            Response::error('bad_request', 'Either message.graphMessageId or message.internetMessageId is required', 400);
            return null;
        }

        $webLink = $this->nullableString($message['webLink'] ?? null);
        if ($webLink !== null && filter_var($webLink, FILTER_VALIDATE_URL) === false) {
            Response::error('bad_request', 'Invalid message.webLink', 400);
            return null;
        }

        $context = isset($payload['context']) && is_array($payload['context']) ? $payload['context'] : [];
        $personModule = $this->nullableString($context['personModule'] ?? null);
        $personId = $this->nullableString($context['personId'] ?? null);
        $accountId = $this->nullableString($context['accountId'] ?? null);
        if ($personModule !== null && !in_array($personModule, self::ALLOWED_CONTEXT_MODULES, true)) {
            Response::error('bad_request', 'Invalid context.personModule', 400);
            return null;
        }

        $createdBy = trim((string) ($session['username'] ?? $claims['name'] ?? $claims['preferred_username'] ?? ''));
        $createdBySubjectId = trim((string) ($session['subjectId'] ?? $claims['sub'] ?? ''));

        return [
            'message' => [
                'graphMessageId' => $graphMessageId,
                'internetMessageId' => $internetMessageId,
                'subject' => $subject,
                'from' => [
                    'name' => $this->nullableString($from['name'] ?? null),
                    'email' => $fromEmail,
                ],
                'receivedDateTime' => $receivedDateTime,
                'conversationId' => $this->nullableString($message['conversationId'] ?? null),
                'bodyPreview' => $this->toShortText($this->nullableString($message['bodyPreview'] ?? null), 500),
                'webLink' => $webLink,
            ],
            'context' => [
                'personModule' => $personModule,
                'personId' => $personId,
                'accountId' => $accountId,
            ],
            'audit' => [
                'createdAt' => gmdate('c'),
                'createdBy' => $createdBy !== '' ? $createdBy : null,
                'createdBySubjectId' => $createdBySubjectId !== '' ? $createdBySubjectId : null,
            ],
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);
        return $normalized === '' ? null : $normalized;
    }

    private function toShortText(?string $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }
        return substr($value, 0, $limit);
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
