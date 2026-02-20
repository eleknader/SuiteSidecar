<?php

declare(strict_types=1);

namespace SuiteSidecar\SuiteCrm;

final class MockAdapter implements CrmAdapterInterface
{
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
                    'link' => 'https://crm.example.com/#Contacts/' . $personId,
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
                'link' => 'https://crm.example.com/#Accounts/mock-account-001',
            ];
        }

        if (isset($includeSet['opportunities'])) {
            $payload['match']['related']['opportunities'] = [
                [
                    'id' => 'mock-opp-001',
                    'name' => 'CNC Lathe Offer',
                    'status' => 'In Progress',
                    'link' => 'https://crm.example.com/#Opportunities/mock-opp-001',
                ],
            ];
        }

        if (isset($includeSet['cases'])) {
            $payload['match']['related']['cases'] = [
                [
                    'id' => 'mock-case-001',
                    'name' => 'Service Request',
                    'status' => 'New',
                    'link' => 'https://crm.example.com/#Cases/mock-case-001',
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
                    'link' => 'https://crm.example.com/#Notes/mock-note-001',
                ],
                [
                    'type' => 'Call',
                    'occurredAt' => gmdate('c', time() - 172800),
                    'title' => 'Follow-up call',
                    'summary' => 'Mock call entry',
                    'link' => 'https://crm.example.com/#Calls/mock-call-001',
                ],
            ];
        }

        return $payload;
    }
}
