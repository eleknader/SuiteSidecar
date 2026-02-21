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

        $options = isset($payload['options']) && is_array($payload['options']) ? $payload['options'] : [];

        try {
            $result = $this->adapter->logEmail([
                'message' => [
                    'internetMessageId' => $internetMessageId,
                    'subject' => $subject,
                    'from' => $from,
                    'to' => $to,
                    'cc' => isset($message['cc']) && is_array($message['cc']) ? $message['cc'] : [],
                    'sentAt' => $sentAt,
                    'receivedAt' => isset($message['receivedAt']) ? (string) $message['receivedAt'] : null,
                    'bodyText' => isset($message['bodyText']) ? (string) $message['bodyText'] : null,
                    'bodyHtml' => isset($message['bodyHtml']) ? (string) $message['bodyHtml'] : null,
                    'attachments' => isset($message['attachments']) && is_array($message['attachments']) ? $message['attachments'] : [],
                ],
                'linkTo' => [
                    'module' => $module,
                    'id' => $linkId,
                ],
                'options' => [
                    'storeBody' => (bool) ($options['storeBody'] ?? false),
                    'storeAttachments' => (bool) ($options['storeAttachments'] ?? false),
                    'maxAttachmentBytes' => isset($options['maxAttachmentBytes']) ? (int) $options['maxAttachmentBytes'] : null,
                ],
            ]);

            Response::json($result, 201);
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
        } catch (SuiteCrmConflictException $e) {
            error_log('[requestId=' . Response::requestId() . '] SuiteCRM dedup conflict during email log');
            Response::error('conflict', $e->getMessage() !== '' ? $e->getMessage() : 'Duplicate record', 409);
        } catch (SuiteCrmHttpException $e) {
            error_log(
                '[requestId=' . Response::requestId() . '] SuiteCRM HTTP error'
                . ' endpoint=' . $e->getEndpoint()
                . ' status=' . $e->getStatus()
            );
            if ($e->getStatus() === 409) {
                Response::error('conflict', 'Duplicate record', 409);
                return;
            }
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
