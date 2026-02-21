<?php

declare(strict_types=1);

namespace SuiteSidecar\SuiteCrm;

final class MockAdapter implements CrmAdapterInterface
{
    private const CRM_BASE_URL = 'https://crm.example.com';

    public function lookupByEmail(string $email, array $include): array
    {
        $includeSet = array_fill_keys($include, true);

        // Keep mock behavior deterministic for local development.
        $shouldMatch = (str_ends_with(strtolower($email), '@example.com') || str_contains($email, '+found'));

        if (!$shouldMatch) {
            return [
                'notFound' => true,
                'match' => null,
                'suggestions' => [],
            ];
        }

        $personId = 'mock-contact-001';

        $payload = [
            'notFound' => false,
            'match' => [
                'person' => [
                    'module' => 'Contacts',
                    'id' => $personId,
                    'displayName' => 'Matti Meikäläinen',
                    'firstName' => 'Matti',
                    'lastName' => 'Meikäläinen',
                    'title' => 'Sales Manager',
                    'email' => $email,
                    'phone' => '+358 40 123 4567',
                    'link' => $this->deepLink('Contacts', $personId),
                ],
            ],
            'suggestions' => [],
        ];

        if (isset($includeSet['account'])) {
            $payload['match']['account'] = [
                'id' => 'mock-account-001',
                'name' => 'Example Oy',
                'phone' => '+358 9 123 456',
                'website' => 'https://example.com',
                'link' => $this->deepLink('Accounts', 'mock-account-001'),
            ];
        }

        if (isset($includeSet['opportunities'])) {
            $payload['match']['related']['opportunities'] = [
                [
                    'id' => 'mock-opp-001',
                    'name' => 'CNC Lathe Offer',
                    'status' => 'In Progress',
                    'link' => $this->deepLink('Opportunities', 'mock-opp-001'),
                ],
            ];
        }

        if (isset($includeSet['cases'])) {
            $payload['match']['related']['cases'] = [
                [
                    'id' => 'mock-case-001',
                    'name' => 'Service Request',
                    'status' => 'New',
                    'link' => $this->deepLink('Cases', 'mock-case-001'),
                ],
            ];
        }

        if (isset($includeSet['timeline'])) {
            $payload['match']['timeline'] = [
                [
                    'type' => 'Note',
                    'occurredAt' => gmdate('c', time() - 86400),
                    'title' => 'Email logged from Outlook',
                    'summary' => 'Mock timeline entry',
                    'link' => $this->deepLink('Notes', 'mock-note-001'),
                ],
                [
                    'type' => 'Call',
                    'occurredAt' => gmdate('c', time() - 172800),
                    'title' => 'Follow-up call',
                    'summary' => 'Mock call entry',
                    'link' => $this->deepLink('Calls', 'mock-call-001'),
                ],
            ];
        }

        return $payload;
    }

    public function createContact(array $payload): array
    {
        $firstName = trim((string) ($payload['firstName'] ?? ''));
        $lastName = trim((string) ($payload['lastName'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));

        $id = 'mock-contact-' . bin2hex(random_bytes(6));
        $displayName = trim($firstName . ' ' . $lastName);
        if ($displayName === '') {
            $displayName = $email !== '' ? $email : 'Mock Contact';
        }

        return [
            'module' => 'Contacts',
            'id' => $id,
            'displayName' => $displayName,
            'link' => $this->deepLink('Contacts', $id),
        ];
    }

    public function createLead(array $payload): array
    {
        $firstName = trim((string) ($payload['firstName'] ?? ''));
        $lastName = trim((string) ($payload['lastName'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));

        $id = 'mock-lead-' . bin2hex(random_bytes(6));
        $displayName = trim($firstName . ' ' . $lastName);
        if ($displayName === '') {
            $displayName = $email !== '' ? $email : 'Mock Lead';
        }

        return [
            'module' => 'Leads',
            'id' => $id,
            'displayName' => $displayName,
            'link' => $this->deepLink('Leads', $id),
        ];
    }

    public function logEmail(array $payload): array
    {
        $message = isset($payload['message']) && is_array($payload['message']) ? $payload['message'] : [];

        $internetMessageId = strtolower(trim((string) ($message['internetMessageId'] ?? '')));
        if (str_contains($internetMessageId, 'duplicate') || str_contains($internetMessageId, '+dup')) {
            throw new SuiteCrmConflictException('Email has already been logged');
        }

        $subject = trim((string) ($message['subject'] ?? 'Email from Outlook'));
        $id = 'mock-note-' . bin2hex(random_bytes(6));

        return [
            'loggedRecord' => [
                'module' => 'Notes',
                'id' => $id,
                'displayName' => $subject !== '' ? $subject : 'Email from Outlook',
                'link' => $this->deepLink('Notes', $id),
            ],
            'deduplicated' => false,
        ];
    }

    private function deepLink(string $module, string $id): string
    {
        return self::CRM_BASE_URL . '/#/' . strtolower(trim($module)) . '/record/' . rawurlencode(trim($id));
    }
}
