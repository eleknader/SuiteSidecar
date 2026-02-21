<?php

declare(strict_types=1);

namespace SuiteSidecar;

use SuiteSidecar\Http\Response;
use SuiteSidecar\SuiteCrm\CrmAdapterInterface;
use SuiteSidecar\SuiteCrm\SuiteCrmAuthException;
use SuiteSidecar\SuiteCrm\SuiteCrmBadResponseException;
use SuiteSidecar\SuiteCrm\SuiteCrmException;
use SuiteSidecar\SuiteCrm\SuiteCrmHttpException;

final class EntitiesController
{
    public function __construct(
        private readonly CrmAdapterInterface $adapter
    ) {
    }

    public function createContact(): void
    {
        $payload = $this->readJsonBody();
        if ($payload === null) {
            Response::error('bad_request', 'Invalid JSON body', 400);
            return;
        }

        $normalized = $this->normalizeContactPayload($payload);
        if ($normalized === null) {
            return;
        }

        $this->handleCreate(static fn (CrmAdapterInterface $adapter, array $data): array => $adapter->createContact($data), $normalized);
    }

    public function createLead(): void
    {
        $payload = $this->readJsonBody();
        if ($payload === null) {
            Response::error('bad_request', 'Invalid JSON body', 400);
            return;
        }

        $normalized = $this->normalizeLeadPayload($payload);
        if ($normalized === null) {
            return;
        }

        $this->handleCreate(static fn (CrmAdapterInterface $adapter, array $data): array => $adapter->createLead($data), $normalized);
    }

    private function handleCreate(callable $operation, array $normalizedPayload): void
    {
        try {
            $result = $operation($this->adapter, $normalizedPayload);
            Response::json($result, 201);
        } catch (SuiteCrmAuthException $e) {
            error_log('[requestId=' . Response::requestId() . '] SuiteCRM auth failed during entity create');
            $statusCode = $e->getStatusCode();
            if (!in_array($statusCode, [401, 502], true)) {
                $statusCode = 401;
            }
            Response::error('suitecrm_auth_failed', 'SuiteCRM authentication failed', $statusCode);
        } catch (SuiteCrmBadResponseException) {
            error_log('[requestId=' . Response::requestId() . '] SuiteCRM returned invalid response payload for entity create');
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
            error_log('[requestId=' . Response::requestId() . '] SuiteCRM request failed during entity create');
            Response::error('suitecrm_unreachable', 'SuiteCRM is temporarily unreachable', 502);
        }
    }

    private function normalizeContactPayload(array $payload): ?array
    {
        $firstName = trim((string) ($payload['firstName'] ?? ''));
        $lastName = trim((string) ($payload['lastName'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));

        if ($firstName === '' || $lastName === '' || $email === '') {
            Response::error('bad_request', 'Missing required fields: firstName, lastName, email', 400);
            return null;
        }

        if (!$this->isValidEmail($email)) {
            Response::error('bad_request', 'Invalid email format', 400);
            return null;
        }

        try {
            $customFields = $this->normalizeCustomFields($payload['customFields'] ?? null);
        } catch (\InvalidArgumentException $e) {
            Response::error('bad_request', $e->getMessage(), 400);
            return null;
        }

        return [
            'firstName' => $firstName,
            'lastName' => $lastName,
            'email' => $email,
            'title' => $this->nullableString($payload['title'] ?? null),
            'phone' => $this->nullableString($payload['phone'] ?? null),
            'accountName' => $this->nullableString($payload['accountName'] ?? null),
            'source' => $this->nullableString($payload['source'] ?? null),
            'customFields' => $customFields,
        ];
    }

    private function normalizeLeadPayload(array $payload): ?array
    {
        $firstName = trim((string) ($payload['firstName'] ?? ''));
        $lastName = trim((string) ($payload['lastName'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));

        if ($firstName === '' || $lastName === '' || $email === '') {
            Response::error('bad_request', 'Missing required fields: firstName, lastName, email', 400);
            return null;
        }

        if (!$this->isValidEmail($email)) {
            Response::error('bad_request', 'Invalid email format', 400);
            return null;
        }

        try {
            $customFields = $this->normalizeCustomFields($payload['customFields'] ?? null);
        } catch (\InvalidArgumentException $e) {
            Response::error('bad_request', $e->getMessage(), 400);
            return null;
        }

        return [
            'firstName' => $firstName,
            'lastName' => $lastName,
            'email' => $email,
            'title' => $this->nullableString($payload['title'] ?? null),
            'phone' => $this->nullableString($payload['phone'] ?? null),
            'company' => $this->nullableString($payload['company'] ?? null),
            'source' => $this->nullableString($payload['source'] ?? null),
            'customFields' => $customFields,
        ];
    }

    private function normalizeCustomFields(mixed $customFields): ?array
    {
        if ($customFields === null) {
            return null;
        }

        if (!is_array($customFields)) {
            throw new \InvalidArgumentException('customFields must be an object');
        }

        $normalized = [];
        foreach ($customFields as $key => $value) {
            $field = trim((string) $key);
            if ($field === '') {
                continue;
            }

            if (!is_scalar($value) && $value !== null) {
                throw new \InvalidArgumentException('customFields values must be scalar or null');
            }

            $normalized[$field] = $value;
        }

        return $normalized;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $result = trim((string) $value);
        return $result === '' ? null : $result;
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
