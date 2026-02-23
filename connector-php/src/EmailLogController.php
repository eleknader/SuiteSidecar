<?php

declare(strict_types=1);

namespace SuiteSidecar;

use SuiteSidecar\Http\Response;
use SuiteSidecar\SuiteCrm\CrmAdapterInterface;
use SuiteSidecar\SuiteCrm\SuiteCrmAuthException;
use SuiteSidecar\SuiteCrm\SuiteCrmBadResponseException;
use SuiteSidecar\SuiteCrm\SuiteCrmConflictException;
use SuiteSidecar\SuiteCrm\SuiteCrmException;
use SuiteSidecar\SuiteCrm\SuiteCrmHttpException;

final class EmailLogController
{
    private const ALLOWED_LINK_MODULES = ['Contacts', 'Leads', 'Accounts', 'Opportunities', 'Cases'];

    public function __construct(
        private readonly CrmAdapterInterface $adapter
    ) {
    }

    public function log(): void
    {
        $runtimeLimits = RuntimeLimits::resolve();
        $contentLengthBytes = RuntimeLimits::currentContentLengthBytes();
        $maxRequestBytes = isset($runtimeLimits['maxRequestBytes']) && is_int($runtimeLimits['maxRequestBytes'])
            ? $runtimeLimits['maxRequestBytes']
            : null;
        if ($maxRequestBytes !== null && $contentLengthBytes !== null && $contentLengthBytes > $maxRequestBytes) {
            Response::error(
                'payload_too_large',
                'Request payload exceeds connector request size limit',
                413,
                $this->buildPayloadTooLargeDetails($runtimeLimits, $contentLengthBytes)
            );
            return;
        }

        $payload = $this->readJsonBody($runtimeLimits);
        if ($payload === null) {
            return;
        }

        $normalized = $this->normalizePayload($payload);
        if ($normalized === null) {
            return;
        }

        try {
            $result = $this->adapter->logEmail($normalized);
            Response::json($result, 201);
        } catch (SuiteCrmConflictException) {
            Response::error('conflict', 'Email has already been logged', 409);
        } catch (SuiteCrmAuthException $e) {
            error_log('[requestId=' . Response::requestId() . '] SuiteCRM auth failed during email log');
            $statusCode = $e->getStatusCode();
            if (!in_array($statusCode, [401, 502], true)) {
                $statusCode = 401;
            }
            Response::error('suitecrm_auth_failed', 'SuiteCRM authentication failed', $statusCode);
        } catch (SuiteCrmBadResponseException) {
            error_log('[requestId=' . Response::requestId() . '] SuiteCRM returned invalid response payload for email log');
            Response::error('suitecrm_bad_response', 'SuiteCRM returned an invalid response', 502);
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
            error_log('[requestId=' . Response::requestId() . '] SuiteCRM request failed during email log');
            Response::error('suitecrm_unreachable', 'SuiteCRM is temporarily unreachable', 502);
        }
    }

    private function normalizePayload(array $payload): ?array
    {
        $message = isset($payload['message']) && is_array($payload['message']) ? $payload['message'] : null;
        $linkTo = isset($payload['linkTo']) && is_array($payload['linkTo']) ? $payload['linkTo'] : null;
        if ($message === null || $linkTo === null) {
            Response::error('bad_request', 'Missing required fields: message, linkTo', 400);
            return null;
        }

        $internetMessageId = trim((string) ($message['internetMessageId'] ?? ''));
        $subject = trim((string) ($message['subject'] ?? ''));
        $sentAt = trim((string) ($message['sentAt'] ?? ''));
        $from = isset($message['from']) && is_array($message['from']) ? $message['from'] : [];
        $to = isset($message['to']) && is_array($message['to']) ? $message['to'] : [];

        if ($internetMessageId === '' || $subject === '' || $sentAt === '') {
            Response::error('bad_request', 'Missing required message fields', 400);
            return null;
        }

        if ($this->parseDateTime($sentAt) === null) {
            Response::error('bad_request', 'Invalid sentAt datetime format', 400);
            return null;
        }

        $fromEmail = trim((string) ($from['email'] ?? ''));
        if (!$this->isValidEmail($fromEmail)) {
            Response::error('bad_request', 'Invalid from.email', 400);
            return null;
        }

        if ($to === []) {
            Response::error('bad_request', 'Missing required message recipients: to', 400);
            return null;
        }

        foreach ($to as $recipient) {
            $recipientEmail = is_array($recipient) ? trim((string) ($recipient['email'] ?? '')) : '';
            if (!$this->isValidEmail($recipientEmail)) {
                Response::error('bad_request', 'Invalid recipient email in message.to', 400);
                return null;
            }
        }

        $module = trim((string) ($linkTo['module'] ?? ''));
        $linkId = trim((string) ($linkTo['id'] ?? ''));
        if ($module === '' || $linkId === '') {
            Response::error('bad_request', 'Missing required linkTo fields', 400);
            return null;
        }

        if (!in_array($module, self::ALLOWED_LINK_MODULES, true)) {
            Response::error('bad_request', 'Invalid linkTo.module', 400);
            return null;
        }

        return [
            'message' => [
                'internetMessageId' => $internetMessageId,
                'subject' => $subject,
                'sentAt' => $sentAt,
                'from' => $from,
                'to' => $to,
                'cc' => isset($message['cc']) && is_array($message['cc']) ? $message['cc'] : [],
                'receivedAt' => isset($message['receivedAt']) ? (string) $message['receivedAt'] : null,
                'bodyText' => isset($message['bodyText']) ? (string) $message['bodyText'] : null,
                'bodyHtml' => isset($message['bodyHtml']) ? (string) $message['bodyHtml'] : null,
                'attachments' => isset($message['attachments']) && is_array($message['attachments']) ? $message['attachments'] : [],
            ],
            'linkTo' => [
                'module' => $module,
                'id' => $linkId,
            ],
            'options' => isset($payload['options']) && is_array($payload['options']) ? $payload['options'] : [],
        ];
    }

    private function readJsonBody(array $runtimeLimits): ?array
    {
        $rawBody = file_get_contents('php://input');
        if (!is_string($rawBody) || trim($rawBody) === '') {
            $contentLengthBytes = RuntimeLimits::currentContentLengthBytes();
            $maxRequestBytes = isset($runtimeLimits['maxRequestBytes']) && is_int($runtimeLimits['maxRequestBytes'])
                ? $runtimeLimits['maxRequestBytes']
                : null;
            if ($contentLengthBytes !== null && $contentLengthBytes > 0 && $maxRequestBytes !== null && $contentLengthBytes > $maxRequestBytes) {
                Response::error(
                    'payload_too_large',
                    'Request payload exceeds connector request size limit',
                    413,
                    $this->buildPayloadTooLargeDetails($runtimeLimits, $contentLengthBytes)
                );
                return null;
            }

            Response::error('bad_request', 'Invalid JSON body', 400);
            return null;
        }

        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            Response::error('bad_request', 'Invalid JSON body', 400);
            return null;
        }

        return $decoded;
    }

    private function buildPayloadTooLargeDetails(array $runtimeLimits, ?int $contentLengthBytes): array
    {
        $details = [
            'contentLengthBytes' => $contentLengthBytes,
            'maxRequestBytes' => isset($runtimeLimits['maxRequestBytes']) ? $runtimeLimits['maxRequestBytes'] : null,
            'maxAttachmentBytes' => isset($runtimeLimits['maxAttachmentBytes']) ? $runtimeLimits['maxAttachmentBytes'] : null,
            'phpPostMaxBytes' => isset($runtimeLimits['phpPostMaxBytes']) ? $runtimeLimits['phpPostMaxBytes'] : null,
            'phpUploadMaxFileSizeBytes' => isset($runtimeLimits['phpUploadMaxFileSizeBytes'])
                ? $runtimeLimits['phpUploadMaxFileSizeBytes']
                : null,
        ];

        return array_filter(
            $details,
            static fn ($value): bool => $value !== null
        );
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
