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

    public function logEmail(array $payload): array
    {
        $message = isset($payload['message']) && is_array($payload['message']) ? $payload['message'] : [];
        $linkTo = isset($payload['linkTo']) && is_array($payload['linkTo']) ? $payload['linkTo'] : [];
        $options = isset($payload['options']) && is_array($payload['options']) ? $payload['options'] : [];

        $subject = trim((string) ($message['subject'] ?? 'Email log'));
        if ($subject === '') {
            $subject = 'Email log';
        }

        $storeBody = (bool) ($options['storeBody'] ?? false);
        $description = $this->buildDescription($message, $storeBody);
        $parentType = trim((string) ($linkTo['module'] ?? ''));
        $parentId = trim((string) ($linkTo['id'] ?? ''));

        $response = $this->client->post('/Api/V8/module/Notes', [
            'data' => [
                'type' => 'Notes',
                'attributes' => [
                    'name' => substr($subject, 0, 255),
                    'description' => $description,
                    'parent_type' => $parentType,
                    'parent_id' => $parentId,
                ],
            ],
        ]);

        $item = $response['data'] ?? null;
        if (!is_array($item)) {
            throw new SuiteCrmBadResponseException('SuiteCRM response is missing created note payload');
        }

        $id = isset($item['id']) ? (string) $item['id'] : '';
        if ($id === '') {
            throw new SuiteCrmBadResponseException('SuiteCRM response is missing created note id');
        }

        $attributes = $item['attributes'] ?? [];
        $displayName = $subject;
        if (is_array($attributes) && isset($attributes['name']) && trim((string) $attributes['name']) !== '') {
            $displayName = (string) $attributes['name'];
        }

        return [
            'loggedRecord' => [
                'module' => 'Notes',
                'id' => $id,
                'displayName' => substr($displayName, 0, 255),
                'link' => $this->profile->deepLink('Notes', $id),
            ],
            'deduplicated' => false,
        ];
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

    private function buildDescription(array $message, bool $storeBody): string
    {
        $lines = [];
        $lines[] = 'Logged by SuiteSidecar Connector';

        $internetMessageId = trim((string) ($message['internetMessageId'] ?? ''));
        if ($internetMessageId !== '') {
            $lines[] = 'Message-ID: ' . $internetMessageId;
        }

        $from = isset($message['from']) && is_array($message['from']) ? $message['from'] : [];
        $fromEmail = trim((string) ($from['email'] ?? ''));
        if ($fromEmail !== '') {
            $lines[] = 'From: ' . $fromEmail;
        }

        $toAddresses = $this->collectEmails($message['to'] ?? []);
        if ($toAddresses !== '') {
            $lines[] = 'To: ' . $toAddresses;
        }

        $ccAddresses = $this->collectEmails($message['cc'] ?? []);
        if ($ccAddresses !== '') {
            $lines[] = 'Cc: ' . $ccAddresses;
        }

        $sentAt = trim((string) ($message['sentAt'] ?? ''));
        if ($sentAt !== '') {
            $lines[] = 'Sent: ' . $sentAt;
        }

        if ($storeBody) {
            $bodyText = trim((string) ($message['bodyText'] ?? ''));
            if ($bodyText !== '') {
                $lines[] = '';
                $lines[] = 'Body:';
                $lines[] = $bodyText;
            }
        }

        return substr(implode("\n", $lines), 0, 10000);
    }

    private function collectEmails(mixed $parties): string
    {
        if (!is_array($parties)) {
            return '';
        }

        $emails = [];
        foreach ($parties as $party) {
            if (!is_array($party)) {
                continue;
            }
            $email = trim((string) ($party['email'] ?? ''));
            if ($email !== '') {
                $emails[] = $email;
            }
        }

        return implode(', ', $emails);
    }
}
