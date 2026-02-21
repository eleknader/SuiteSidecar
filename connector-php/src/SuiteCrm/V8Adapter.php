<?php

declare(strict_types=1);

namespace SuiteSidecar\SuiteCrm;

final class V8Adapter implements CrmAdapterInterface
{
    public function __construct(
        private readonly Profile $profile,
        private readonly V8Client $client,
    ) {
    }

    public function lookupByEmail(string $email, array $include): array
    {
        $includeSet = array_fill_keys($include, true);

        $contact = $this->queryFirstPerson('Contacts', $email);
        $module = 'Contacts';

        if ($contact === null) {
            $contact = $this->queryFirstPerson('Leads', $email);
            $module = 'Leads';
        }

        if ($contact === null) {
            return [
                'notFound' => true,
                'match' => null,
                'suggestions' => [],
            ];
        }

        $payload = [
            'notFound' => false,
            'match' => [
                'person' => $this->mapPerson($contact, $module, $email),
            ],
            'suggestions' => [],
        ];

        if ($module === 'Contacts' && isset($includeSet['account'])) {
            $account = $this->fetchRelatedAccount($contact);
            if ($account !== null) {
                $payload['match']['account'] = $account;
            }
        }

        return $payload;
    }

    public function createContact(array $payload): array
    {
        $attributes = [
            'first_name' => (string) ($payload['firstName'] ?? ''),
            'last_name' => (string) ($payload['lastName'] ?? ''),
            'email1' => (string) ($payload['email'] ?? ''),
            'title' => (string) ($payload['title'] ?? ''),
            'phone_work' => (string) ($payload['phone'] ?? ''),
        ];

        $accountName = trim((string) ($payload['accountName'] ?? ''));
        if ($accountName !== '') {
            $attributes['account_name'] = $accountName;
        }

        $source = trim((string) ($payload['source'] ?? ''));
        if ($source !== '') {
            $attributes['lead_source'] = $source;
        }

        $this->mergeCustomFields($attributes, $payload['customFields'] ?? null);

        $response = $this->client->post('/Api/V8/module/Contacts', [
            'data' => [
                'type' => 'Contacts',
                'attributes' => $attributes,
            ],
        ]);

        return $this->mapEntityCreateResponse($response, 'Contacts', 'email1');
    }

    public function createLead(array $payload): array
    {
        $attributes = [
            'first_name' => (string) ($payload['firstName'] ?? ''),
            'last_name' => (string) ($payload['lastName'] ?? ''),
            'email1' => (string) ($payload['email'] ?? ''),
            'title' => (string) ($payload['title'] ?? ''),
            'phone_work' => (string) ($payload['phone'] ?? ''),
        ];

        $company = trim((string) ($payload['company'] ?? ''));
        if ($company !== '') {
            $attributes['account_name'] = $company;
        }

        $source = trim((string) ($payload['source'] ?? ''));
        if ($source !== '') {
            $attributes['lead_source'] = $source;
        }

        $this->mergeCustomFields($attributes, $payload['customFields'] ?? null);

        $response = $this->client->post('/Api/V8/module/Leads', [
            'data' => [
                'type' => 'Leads',
                'attributes' => $attributes,
            ],
        ]);

        return $this->mapEntityCreateResponse($response, 'Leads', 'email1');
    }

    private function queryFirstPerson(string $module, string $email): ?array
    {
        $response = $this->client->get('/Api/V8/module/' . $module, [
            'filter[email1][eq]' => $email,
            'page[size]' => 1,
            'fields[' . $module . ']' => 'first_name,last_name,email1,phone_work,title',
        ]);

        $data = $response['data'] ?? null;
        if (!is_array($data) || $data === []) {
            return null;
        }

        $firstItem = $data[0] ?? null;
        if (!is_array($firstItem)) {
            throw new SuiteCrmBadResponseException('SuiteCRM response is missing item data');
        }

        return $firstItem;
    }

    private function mapPerson(array $item, string $module, string $emailFallback): array
    {
        $id = isset($item['id']) ? (string) $item['id'] : '';
        if ($id === '') {
            throw new SuiteCrmBadResponseException('SuiteCRM response item missing id');
        }

        $attributes = $item['attributes'] ?? [];
        if (!is_array($attributes)) {
            throw new SuiteCrmBadResponseException('SuiteCRM response item has invalid attributes');
        }

        $firstName = (string) ($attributes['first_name'] ?? '');
        $lastName = (string) ($attributes['last_name'] ?? '');
        $displayName = trim($firstName . ' ' . $lastName);
        if ($displayName === '') {
            $displayName = (string) ($attributes['name'] ?? $emailFallback);
        }

        $phone = '';
        foreach (['phone_work', 'phone_mobile', 'phone_home'] as $phoneKey) {
            if (isset($attributes[$phoneKey]) && trim((string) $attributes[$phoneKey]) !== '') {
                $phone = (string) $attributes[$phoneKey];
                break;
            }
        }

        return [
            'module' => $module,
            'id' => $id,
            'displayName' => $displayName,
            'firstName' => $firstName !== '' ? $firstName : null,
            'lastName' => $lastName !== '' ? $lastName : null,
            'title' => isset($attributes['title']) ? (string) $attributes['title'] : null,
            'email' => isset($attributes['email1']) && trim((string) $attributes['email1']) !== ''
                ? (string) $attributes['email1']
                : $emailFallback,
            'phone' => $phone !== '' ? $phone : null,
            'link' => $this->profile->deepLink($module, $id),
        ];
    }

    private function fetchRelatedAccount(array $contact): ?array
    {
        $relationships = $contact['relationships'] ?? null;
        if (!is_array($relationships)) {
            return null;
        }

        $accounts = $relationships['accounts']['data'] ?? null;
        if (!is_array($accounts)) {
            return null;
        }

        $accountId = null;
        if (isset($accounts[0]['id']) && trim((string) $accounts[0]['id']) !== '') {
            $accountId = (string) $accounts[0]['id'];
        } elseif (isset($accounts['id']) && trim((string) $accounts['id']) !== '') {
            $accountId = (string) $accounts['id'];
        }

        if ($accountId === null) {
            return null;
        }

        try {
            $response = $this->client->get('/Api/V8/module/Accounts/' . rawurlencode($accountId), [
                'fields[Accounts]' => 'name,phone_office,website',
            ]);
        } catch (SuiteCrmException) {
            return null;
        }

        $data = $response['data'] ?? null;
        if (!is_array($data)) {
            return null;
        }

        $id = isset($data['id']) ? (string) $data['id'] : $accountId;
        $attributes = $data['attributes'] ?? [];
        if (!is_array($attributes)) {
            return null;
        }

        $name = isset($attributes['name']) ? trim((string) $attributes['name']) : '';
        if ($name === '') {
            return null;
        }

        return [
            'id' => $id,
            'name' => $name,
            'phone' => isset($attributes['phone_office']) ? (string) $attributes['phone_office'] : null,
            'website' => isset($attributes['website']) ? (string) $attributes['website'] : null,
            'link' => $this->profile->deepLink('Accounts', $id),
        ];
    }

    private function mergeCustomFields(array &$attributes, mixed $customFields): void
    {
        if (!is_array($customFields)) {
            return;
        }

        foreach ($customFields as $key => $value) {
            $field = trim((string) $key);
            if ($field === '') {
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $attributes[$field] = $value;
            }
        }
    }

    private function mapEntityCreateResponse(array $response, string $module, string $emailField): array
    {
        $item = $response['data'] ?? null;
        if (!is_array($item)) {
            throw new SuiteCrmBadResponseException('SuiteCRM response is missing created entity payload');
        }

        $id = isset($item['id']) ? (string) $item['id'] : '';
        if ($id === '') {
            throw new SuiteCrmBadResponseException('SuiteCRM response is missing created entity id');
        }

        $attributes = $item['attributes'] ?? [];
        if (!is_array($attributes)) {
            $attributes = [];
        }

        $firstName = trim((string) ($attributes['first_name'] ?? ''));
        $lastName = trim((string) ($attributes['last_name'] ?? ''));
        $displayName = trim($firstName . ' ' . $lastName);
        if ($displayName === '') {
            $displayName = trim((string) ($attributes['name'] ?? ''));
        }
        if ($displayName === '') {
            $displayName = trim((string) ($attributes[$emailField] ?? ''));
        }
        if ($displayName === '') {
            $displayName = $module . ' ' . $id;
        }

        return [
            'module' => $module,
            'id' => $id,
            'displayName' => substr($displayName, 0, 255),
            'link' => $this->profile->deepLink($module, $id),
        ];
    }
}
