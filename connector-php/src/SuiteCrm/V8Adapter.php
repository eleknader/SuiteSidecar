<?php

declare(strict_types=1);

namespace SuiteSidecar\SuiteCrm;

use SuiteSidecar\EmailActionLogStore;

final class V8Adapter implements CrmAdapterInterface
{
    public function __construct(
        private readonly Profile $profile,
        private readonly V8Client $client,
        private readonly ?EmailActionLogStore $emailActionLogStore = null,
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

        $person = $this->mapPerson($contact, $module, $email);

        $payload = [
            'notFound' => false,
            'match' => [
                'person' => $person,
                'actions' => $this->buildPersonActionLinks(
                    $module,
                    (string) $person['id'],
                    (string) $person['displayName']
                ),
            ],
            'suggestions' => [],
        ];

        if ($module === 'Contacts' && isset($includeSet['account'])) {
            $account = $this->fetchRelatedAccount($contact);
            if ($account !== null) {
                $payload['match']['account'] = $account;
            }
        }

        if (isset($includeSet['timeline'])) {
            $payload['match']['timeline'] = $this->fetchTimelineForParent($module, (string) $person['id']);
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

        $response = $this->client->post('/Api/V8/module', [
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

        $response = $this->client->post('/Api/V8/module', [
            'data' => [
                'type' => 'Leads',
                'attributes' => $attributes,
            ],
        ]);

        return $this->mapEntityCreateResponse($response, 'Leads', 'email1');
    }

    public function logEmail(array $payload): array
    {
        $message = isset($payload['message']) && is_array($payload['message']) ? $payload['message'] : [];
        $linkTo = isset($payload['linkTo']) && is_array($payload['linkTo']) ? $payload['linkTo'] : [];
        $options = isset($payload['options']) && is_array($payload['options']) ? $payload['options'] : [];

        $internetMessageId = trim((string) ($message['internetMessageId'] ?? ''));
        if ($internetMessageId === '') {
            throw new SuiteCrmBadResponseException('Missing message.internetMessageId for email logging');
        }

        $messageKey = $this->buildMessageKey($internetMessageId);
        $existingRecord = $this->findExistingLoggedEmail($messageKey);
        if ($existingRecord !== null) {
            throw new SuiteCrmConflictException('Email has already been logged', $existingRecord);
        }

        $subject = trim((string) ($message['subject'] ?? ''));
        $linkModule = trim((string) ($linkTo['module'] ?? ''));
        $linkId = trim((string) ($linkTo['id'] ?? ''));
        $storeBody = (bool) ($options['storeBody'] ?? false);
        $storeAttachments = (bool) ($options['storeAttachments'] ?? false);
        $maxAttachmentBytes = $this->toPositiveIntOrNull($options['maxAttachmentBytes'] ?? null);
        $attachments = isset($message['attachments']) && is_array($message['attachments']) ? $message['attachments'] : [];

        $attributes = [
            'name' => substr($subject !== '' ? $subject : 'Email from Outlook', 0, 255),
            'suitesidecar_message_id_c' => $messageKey,
            'suitesidecar_profile_id_c' => $this->profile->id,
            'parent_type' => $linkModule,
            'parent_id' => $linkId,
        ];

        $bodyText = isset($message['bodyText']) ? trim((string) $message['bodyText']) : '';
        if ($storeBody && $bodyText !== '') {
            $attributes['description'] = $bodyText;
        }

        $response = $this->client->post('/Api/V8/module', [
            'data' => [
                'type' => 'Notes',
                'attributes' => $attributes,
            ],
        ]);

        $loggedRecord = $this->mapEntityCreateResponse($response, 'Notes', 'name');

        if ($storeAttachments && $attachments !== []) {
            $this->persistAttachmentRecords(
                $attachments,
                $messageKey,
                $linkModule,
                $linkId,
                (string) $loggedRecord['id'],
                $maxAttachmentBytes
            );
        }

        return [
            'loggedRecord' => $loggedRecord,
            'deduplicated' => false,
        ];
    }

    public function createTaskFromEmail(array $payload): array
    {
        $message = isset($payload['message']) && is_array($payload['message']) ? $payload['message'] : [];
        $audit = isset($payload['audit']) && is_array($payload['audit']) ? $payload['audit'] : [];
        $context = isset($payload['context']) && is_array($payload['context']) ? $payload['context'] : [];

        $graphMessageId = $this->normalizeTaskMessageId($message['graphMessageId'] ?? null, false);
        $internetMessageId = $this->normalizeTaskMessageId($message['internetMessageId'] ?? null, true);
        if ($graphMessageId === null && $internetMessageId === null) {
            throw new SuiteCrmBadResponseException('Task create requires graphMessageId or internetMessageId');
        }

        $actionLogStore = $this->emailActionLogStore ?? new EmailActionLogStore();
        $existingEntry = $actionLogStore->findTaskByMessageKeys($this->profile->id, $graphMessageId, $internetMessageId);
        if ($existingEntry !== null) {
            $existingTaskId = trim((string) (($existingEntry['task']['id'] ?? '')));
            if ($existingTaskId !== '') {
                $existingTask = $this->fetchTaskById($existingTaskId);
                if ($existingTask !== null) {
                    return [
                        'task' => $existingTask,
                        'deduplicated' => true,
                    ];
                }
            }
        }

        $subject = trim((string) ($message['subject'] ?? ''));
        $from = isset($message['from']) && is_array($message['from']) ? $message['from'] : [];
        $fromEmail = trim((string) ($from['email'] ?? ''));
        $fromName = trim((string) ($from['name'] ?? ''));
        $receivedDateTime = trim((string) ($message['receivedDateTime'] ?? ''));
        $conversationId = trim((string) ($message['conversationId'] ?? ''));
        $webLink = trim((string) ($message['webLink'] ?? ''));
        $bodyPreview = trim((string) ($message['bodyPreview'] ?? ''));

        $resolvedLink = $this->resolveTaskLinkTarget($fromEmail, $context);
        $attributes = [
            'name' => $this->toShortText($subject !== '' ? $subject : 'Follow up email', 255),
            'description' => $this->buildTaskDescription(
                $fromName,
                $fromEmail,
                $receivedDateTime,
                $webLink,
                $bodyPreview,
                $graphMessageId,
                $internetMessageId,
                $conversationId,
                $audit
            ),
        ];

        if ($resolvedLink !== null) {
            $parentModule = trim((string) ($resolvedLink['parentModule'] ?? ''));
            $parentId = trim((string) ($resolvedLink['parentId'] ?? ''));
            if ($parentModule !== '' && $parentId !== '') {
                $attributes['parent_type'] = $parentModule;
                $attributes['parent_id'] = $parentId;
            }

            $contactId = trim((string) ($resolvedLink['contactId'] ?? ''));
            if ($contactId !== '') {
                $attributes['contact_id'] = $contactId;
            }
        }

        $response = $this->client->post('/Api/V8/module', [
            'data' => [
                'type' => 'Tasks',
                'attributes' => $attributes,
            ],
        ]);

        $task = $this->mapEntityCreateResponse($response, 'Tasks', 'name');
        $actionLogStore->saveTaskMessageKeys(
            $this->profile->id,
            $graphMessageId,
            $internetMessageId,
            $task,
            [
                'createdAt' => isset($audit['createdAt']) ? (string) $audit['createdAt'] : gmdate('c'),
                'createdBy' => isset($audit['createdBy']) ? (string) $audit['createdBy'] : null,
                'createdBySubjectId' => isset($audit['createdBySubjectId']) ? (string) $audit['createdBySubjectId'] : null,
                'fromEmail' => $fromEmail !== '' ? $fromEmail : null,
            ]
        );

        return [
            'task' => $task,
            'deduplicated' => false,
        ];
    }

    public function listOpportunities(array $payload): array
    {
        $personModule = trim((string) ($payload['personModule'] ?? ''));
        $personId = trim((string) ($payload['personId'] ?? ''));
        $accountId = trim((string) ($payload['accountId'] ?? ''));
        $limit = $this->toPositiveIntOrNull($payload['limit'] ?? null) ?? 5;
        $limit = min($limit, 20);

        $scope = [
            'module' => null,
            'id' => null,
            'mode' => 'none',
        ];

        if ($accountId !== '') {
            $scope = [
                'module' => 'Accounts',
                'id' => $accountId,
                'mode' => 'account',
            ];
        } elseif ($personModule === 'Contacts' && $personId !== '') {
            $contactAccount = $this->fetchAccountForContactId($personId);
            if ($contactAccount !== null) {
                $scope = [
                    'module' => 'Accounts',
                    'id' => (string) ($contactAccount['id'] ?? ''),
                    'mode' => 'account',
                ];
            } else {
                $scope = [
                    'module' => 'Contacts',
                    'id' => $personId,
                    'mode' => 'contact',
                ];
            }
        } elseif ($personModule === 'Leads' && $personId !== '') {
            $scope = [
                'module' => 'Leads',
                'id' => $personId,
                'mode' => 'lead',
            ];
        }

        $rows = [];
        if ($scope['module'] === 'Accounts' && $scope['id'] !== null) {
            $rows = $this->fetchRelationshipRows(
                'Accounts',
                (string) $scope['id'],
                'opportunities',
                'Opportunities',
                $limit
            );
        } elseif ($scope['module'] === 'Contacts' && $scope['id'] !== null) {
            $rows = $this->fetchRelationshipRows(
                'Contacts',
                (string) $scope['id'],
                'opportunities',
                'Opportunities',
                $limit
            );
        }

        $items = [];
        foreach ($rows as $row) {
            $mapped = $this->mapOpportunitySummary($row);
            if ($mapped !== null) {
                $items[] = $mapped;
            }
        }

        $viewAllLink = null;
        if ($scope['module'] !== null && $scope['id'] !== null && trim((string) $scope['id']) !== '') {
            $viewAllLink = $this->profile->deepLink((string) $scope['module'], (string) $scope['id']);
        }

        return [
            'items' => $items,
            'viewAllLink' => $viewAllLink,
            'scope' => [
                'mode' => $scope['mode'],
                'module' => $scope['module'],
                'id' => $scope['id'],
            ],
        ];
    }

    private function queryFirstPerson(string $module, string $email): ?array
    {
        $fields = ['first_name', 'last_name', 'email1', 'phone_work', 'title'];
        if ($module === 'Contacts') {
            $fields[] = 'account_id';
        }

        $response = $this->client->get('/Api/V8/module/' . $module, [
            'filter[email1][eq]' => $email,
            'page[size]' => 1,
            'fields[' . $module . ']' => implode(',', $fields),
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

    private function findExistingLoggedEmail(string $messageKey): ?array
    {
        $common = [
            'page[size]' => 5,
            'fields[Notes]' => 'name,suitesidecar_message_id_c,suitesidecar_profile_id_c',
        ];

        $queryVariants = [
            [
                'filter[suitesidecar_message_id_c][eq]' => $messageKey,
                'filter[suitesidecar_profile_id_c][eq]' => $this->profile->id,
            ],
            [
                'filter[0][suitesidecar_message_id_c][eq]' => $messageKey,
                'filter[1][suitesidecar_profile_id_c][eq]' => $this->profile->id,
            ],
            [
                'filter[suitesidecar_message_id_c]' => $messageKey,
                'filter[suitesidecar_profile_id_c]' => $this->profile->id,
            ],
            [
                'filter[0][suitesidecar_message_id_c]' => $messageKey,
                'filter[1][suitesidecar_profile_id_c]' => $this->profile->id,
            ],
            // Fallback: message-only filters in case profile field isn't filterable on target.
            [
                'filter[suitesidecar_message_id_c][eq]' => $messageKey,
            ],
            [
                'filter[0][suitesidecar_message_id_c][eq]' => $messageKey,
            ],
            [
                'filter[suitesidecar_message_id_c]' => $messageKey,
            ],
        ];

        foreach ($queryVariants as $queryVariant) {
            try {
                $response = $this->client->get('/Api/V8/module/Notes', $common + $queryVariant);
            } catch (SuiteCrmHttpException $e) {
                if ($e->getStatus() === 400) {
                    continue;
                }
                throw $e;
            }

            $data = $response['data'] ?? null;
            if (!is_array($data) || $data === []) {
                continue;
            }

            $existing = $this->findMatchingExistingNote($data, $messageKey);
            if ($existing !== null) {
                return $existing;
            }
        }

        return null;
    }

    private function findMatchingExistingNote(array $items, string $messageKey): ?array
    {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $id = isset($item['id']) ? (string) $item['id'] : '';
            if ($id === '') {
                continue;
            }

            $attributes = $item['attributes'] ?? [];
            if (!is_array($attributes)) {
                continue;
            }

            $itemMessageKey = trim((string) ($attributes['suitesidecar_message_id_c'] ?? ''));
            if ($itemMessageKey !== '' && $itemMessageKey !== $messageKey) {
                continue;
            }

            $itemProfileId = trim((string) ($attributes['suitesidecar_profile_id_c'] ?? ''));
            if ($itemProfileId !== '' && $itemProfileId !== $this->profile->id) {
                continue;
            }

            $name = trim((string) ($attributes['name'] ?? ''));
            return [
                'module' => 'Notes',
                'id' => $id,
                'displayName' => $name !== '' ? $name : 'Email from Outlook',
                'link' => $this->profile->deepLink('Notes', $id),
            ];
        }

        return null;
    }

    private function buildMessageKey(string $internetMessageId): string
    {
        $normalizedMessageId = preg_replace('/\s+/', '', strtolower(trim($internetMessageId)));
        $normalizedMessageId = trim((string) $normalizedMessageId, '<>');
        return substr((string) $normalizedMessageId, 0, 191);
    }

    private function buildPersonActionLinks(string $personModule, string $personId, string $personName): array
    {
        $module = trim($personModule);
        $id = trim($personId);
        if ($module === '' || $id === '') {
            return [
                'createCallLink' => null,
                'createMeetingLink' => null,
            ];
        }

        $query = [
            'return_module' => $module,
            'return_action' => 'DetailView',
            'return_id' => $id,
            'parent_type' => $module,
            'parent_id' => $id,
            'parent_name' => $personName,
        ];

        return [
            'createCallLink' => $this->profile->legacyCreateLink('Calls', $query),
            'createMeetingLink' => $this->profile->legacyCreateLink('Meetings', $query),
        ];
    }

    private function normalizeTaskMessageId(mixed $value, bool $internetStyle): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        if ($internetStyle) {
            $normalized = preg_replace('/\s+/', '', strtolower($normalized)) ?? '';
            $normalized = trim($normalized, '<>');
        }

        $normalized = trim($normalized);
        if ($normalized === '') {
            return null;
        }

        return substr($normalized, 0, 255);
    }

    private function fetchTaskById(string $taskId): ?array
    {
        try {
            $response = $this->client->get('/Api/V8/module/Tasks/' . rawurlencode($taskId), [
                'fields[Tasks]' => 'name',
            ]);
        } catch (SuiteCrmException) {
            return null;
        }

        $item = $response['data'] ?? null;
        if (!is_array($item)) {
            return null;
        }

        $id = trim((string) ($item['id'] ?? ''));
        if ($id === '') {
            return null;
        }

        $attributes = $item['attributes'] ?? [];
        if (!is_array($attributes)) {
            $attributes = [];
        }

        $name = trim((string) ($attributes['name'] ?? ''));
        if ($name === '') {
            $name = 'Task ' . $id;
        }

        return [
            'module' => 'Tasks',
            'id' => $id,
            'displayName' => $this->toShortText($name, 255),
            'link' => $this->profile->deepLink('Tasks', $id),
        ];
    }

    private function resolveTaskLinkTarget(string $fromEmail, array $context): ?array
    {
        $preferredModule = trim((string) ($context['personModule'] ?? $context['module'] ?? ''));
        $preferredId = trim((string) ($context['personId'] ?? $context['id'] ?? ''));
        $preferredAccountId = trim((string) ($context['accountId'] ?? ''));

        if ($preferredModule === 'Contacts' && $preferredId !== '') {
            $account = $preferredAccountId !== ''
                ? $this->fetchAccountById($preferredAccountId)
                : $this->fetchAccountForContactId($preferredId);

            if ($account !== null) {
                return [
                    'parentModule' => 'Accounts',
                    'parentId' => (string) ($account['id'] ?? ''),
                    'contactId' => $preferredId,
                ];
            }

            return [
                'parentModule' => 'Contacts',
                'parentId' => $preferredId,
                'contactId' => $preferredId,
            ];
        }

        if ($preferredModule === 'Leads' && $preferredId !== '') {
            return [
                'parentModule' => 'Leads',
                'parentId' => $preferredId,
                'contactId' => null,
            ];
        }

        if ($preferredModule === 'Accounts' && ($preferredAccountId !== '' || $preferredId !== '')) {
            $accountId = $preferredAccountId !== '' ? $preferredAccountId : $preferredId;
            return [
                'parentModule' => 'Accounts',
                'parentId' => $accountId,
                'contactId' => null,
            ];
        }

        $email = trim($fromEmail);
        if ($email === '') {
            return null;
        }

        $contact = $this->queryFirstPerson('Contacts', $email);
        if ($contact !== null) {
            $contactId = trim((string) ($contact['id'] ?? ''));
            if ($contactId !== '') {
                $account = $this->fetchRelatedAccount($contact);
                if ($account !== null) {
                    return [
                        'parentModule' => 'Accounts',
                        'parentId' => (string) ($account['id'] ?? ''),
                        'contactId' => $contactId,
                    ];
                }

                return [
                    'parentModule' => 'Contacts',
                    'parentId' => $contactId,
                    'contactId' => $contactId,
                ];
            }
        }

        $lead = $this->queryFirstPerson('Leads', $email);
        if ($lead !== null) {
            $leadId = trim((string) ($lead['id'] ?? ''));
            if ($leadId !== '') {
                return [
                    'parentModule' => 'Leads',
                    'parentId' => $leadId,
                    'contactId' => null,
                ];
            }
        }

        return null;
    }

    private function buildTaskDescription(
        string $fromName,
        string $fromEmail,
        string $receivedDateTime,
        string $webLink,
        string $bodyPreview,
        ?string $graphMessageId,
        ?string $internetMessageId,
        string $conversationId,
        array $audit
    ): string {
        $lines = ['Created from Outlook email by SuiteSidecar.'];

        $senderLabel = trim($fromName) !== '' ? trim($fromName) . ' <' . trim($fromEmail) . '>' : trim($fromEmail);
        if ($senderLabel !== '') {
            $lines[] = 'From: ' . $this->toShortText($senderLabel, 255);
        }

        if (trim($receivedDateTime) !== '') {
            $lines[] = 'Received: ' . $this->toShortText($receivedDateTime, 64);
        }

        if (trim($webLink) !== '') {
            $lines[] = 'Web link: ' . $this->toShortText($webLink, 255);
        }

        if ($graphMessageId !== null) {
            $lines[] = 'Graph message id: ' . $this->toShortText($graphMessageId, 255);
        }

        if ($internetMessageId !== null) {
            $lines[] = 'Internet message id: ' . $this->toShortText($internetMessageId, 255);
        }

        if (trim($conversationId) !== '') {
            $lines[] = 'Conversation id: ' . $this->toShortText($conversationId, 255);
        }

        $preview = trim($bodyPreview);
        if ($preview !== '') {
            $lines[] = 'Preview: ' . $this->toShortText($preview, 280);
        }

        $auditAt = trim((string) ($audit['createdAt'] ?? ''));
        if ($auditAt !== '') {
            $lines[] = 'Created at: ' . $this->toShortText($auditAt, 64);
        }

        $auditBy = trim((string) ($audit['createdBy'] ?? ''));
        if ($auditBy !== '') {
            $lines[] = 'Created by: ' . $this->toShortText($auditBy, 255);
        }

        $auditBySubject = trim((string) ($audit['createdBySubjectId'] ?? ''));
        if ($auditBySubject !== '') {
            $lines[] = 'Creator session id: ' . $this->toShortText($auditBySubject, 64);
        }

        return $this->toShortText(implode("\n", $lines), 60000);
    }

    private function mapOpportunitySummary(array $row): ?array
    {
        $id = trim((string) ($row['id'] ?? ''));
        if ($id === '') {
            return null;
        }

        $attributes = $row['attributes'] ?? [];
        if (!is_array($attributes)) {
            $attributes = [];
        }

        $name = trim((string) ($attributes['name'] ?? ''));
        if ($name === '') {
            $name = 'Opportunity ' . $id;
        }

        return [
            'id' => $id,
            'name' => $this->toShortText($name, 255),
            'salesStage' => isset($attributes['sales_stage']) ? (string) $attributes['sales_stage'] : null,
            'amount' => isset($attributes['amount']) && is_scalar($attributes['amount']) && is_numeric((string) $attributes['amount'])
                ? (float) $attributes['amount']
                : null,
            'currency' => isset($attributes['currency_id']) ? (string) $attributes['currency_id'] : null,
            'dateClosed' => isset($attributes['date_closed']) ? (string) $attributes['date_closed'] : null,
            'assignedUserName' => isset($attributes['assigned_user_name']) ? (string) $attributes['assigned_user_name'] : null,
            'modifiedDate' => isset($attributes['date_modified']) ? (string) $attributes['date_modified'] : null,
            'link' => $this->profile->deepLink('Opportunities', $id),
        ];
    }

    private function fetchRelationshipRows(
        string $module,
        string $recordId,
        string $relationship,
        string $relatedModule,
        int $limit
    ): array {
        try {
            $response = $this->client->get(
                '/Api/V8/module/' . rawurlencode($module) . '/' . rawurlencode($recordId) . '/relationships/' . rawurlencode($relationship),
                [
                    'page[size]' => $limit,
                    'sort' => '-date_modified',
                    'fields[' . $relatedModule . ']' => 'name,sales_stage,amount,currency_id,date_closed,assigned_user_name,date_modified',
                ]
            );
        } catch (SuiteCrmException) {
            return [];
        }

        $data = $response['data'] ?? null;
        return is_array($data) ? $data : [];
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
        $accountId = null;

        $relationships = $contact['relationships'] ?? null;
        if (is_array($relationships)) {
            $accounts = $relationships['accounts']['data'] ?? null;
            if (is_array($accounts)) {
                if (isset($accounts[0]['id']) && trim((string) $accounts[0]['id']) !== '') {
                    $accountId = (string) $accounts[0]['id'];
                } elseif (isset($accounts['id']) && trim((string) $accounts['id']) !== '') {
                    $accountId = (string) $accounts['id'];
                }
            }
        }

        if ($accountId === null) {
            $attributes = $contact['attributes'] ?? [];
            if (is_array($attributes) && isset($attributes['account_id']) && trim((string) $attributes['account_id']) !== '') {
                $accountId = (string) $attributes['account_id'];
            }
        }

        if ($accountId === null || trim($accountId) === '') {
            return null;
        }

        return $this->fetchAccountById($accountId);
    }

    private function fetchAccountForContactId(string $contactId): ?array
    {
        $id = trim($contactId);
        if ($id === '') {
            return null;
        }

        try {
            $response = $this->client->get('/Api/V8/module/Contacts/' . rawurlencode($id), [
                'fields[Contacts]' => 'account_id',
            ]);
        } catch (SuiteCrmException) {
            return null;
        }

        $data = $response['data'] ?? null;
        if (!is_array($data)) {
            return null;
        }

        $attributes = $data['attributes'] ?? [];
        if (!is_array($attributes)) {
            return null;
        }

        $accountId = trim((string) ($attributes['account_id'] ?? ''));
        if ($accountId === '') {
            return null;
        }

        return $this->fetchAccountById($accountId);
    }

    private function fetchAccountById(string $accountId): ?array
    {
        $id = trim($accountId);
        if ($id === '') {
            return null;
        }

        try {
            $response = $this->client->get('/Api/V8/module/Accounts/' . rawurlencode($id), [
                'fields[Accounts]' => 'name,phone_office,website',
            ]);
        } catch (SuiteCrmException) {
            return null;
        }

        $data = $response['data'] ?? null;
        if (!is_array($data)) {
            return null;
        }

        $rowId = isset($data['id']) ? trim((string) $data['id']) : '';
        $attributes = $data['attributes'] ?? [];
        if (!is_array($attributes)) {
            return null;
        }

        $name = trim((string) ($attributes['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $resolvedId = $rowId !== '' ? $rowId : $id;

        return [
            'id' => $resolvedId,
            'name' => $name,
            'phone' => isset($attributes['phone_office']) ? (string) $attributes['phone_office'] : null,
            'website' => isset($attributes['website']) ? (string) $attributes['website'] : null,
            'link' => $this->profile->deepLink('Accounts', $resolvedId),
        ];
    }

    private function fetchTimelineForParent(string $parentModule, string $parentId): array
    {
        if ($parentId === '') {
            return [];
        }

        $timeline = [];
        foreach ($this->timelineModuleConfigs() as $module => $config) {
            $entries = $this->fetchTimelineEntriesForModule(
                $module,
                $parentModule,
                $parentId,
                $config
            );
            if ($entries !== []) {
                $timeline = array_merge($timeline, $entries);
            }
        }

        usort($timeline, static function (array $left, array $right): int {
            $leftTs = strtotime((string) ($left['occurredAt'] ?? '')) ?: 0;
            $rightTs = strtotime((string) ($right['occurredAt'] ?? '')) ?: 0;
            if ($leftTs === $rightTs) {
                return strcmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
            }
            return $rightTs <=> $leftTs;
        });

        return array_slice($timeline, 0, 20);
    }

    private function fetchTimelineEntriesForModule(
        string $module,
        string $parentModule,
        string $parentId,
        array $config
    ): array {
        $occurredField = isset($config['occurredField']) ? (string) $config['occurredField'] : '';
        if ($occurredField === '') {
            return [];
        }

        $fields = ['name', 'parent_type', 'parent_id', $occurredField];
        $summaryFields = isset($config['summaryFields']) && is_array($config['summaryFields'])
            ? $config['summaryFields']
            : [];
        foreach ($summaryFields as $summaryField) {
            $field = trim((string) $summaryField);
            if ($field !== '') {
                $fields[] = $field;
            }
        }

        $rows = $this->queryModuleByParent($module, $parentModule, $parentId, array_values(array_unique($fields)), 10);
        if ($rows === []) {
            return [];
        }

        $entries = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $entry = $this->mapTimelineEntry($module, $row, $config);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    private function queryModuleByParent(
        string $module,
        string $parentModule,
        string $parentId,
        array $fields,
        int $limit
    ): array {
        $common = [
            'page[size]' => $limit,
            'fields[' . $module . ']' => implode(',', $fields),
        ];

        $variants = [
            [
                'filter[parent_type][eq]' => $parentModule,
                'filter[parent_id][eq]' => $parentId,
            ],
            [
                'filter[0][parent_type][eq]' => $parentModule,
                'filter[1][parent_id][eq]' => $parentId,
            ],
            [
                'filter[parent_type]' => $parentModule,
                'filter[parent_id]' => $parentId,
            ],
            [
                'filter[0][parent_type]' => $parentModule,
                'filter[1][parent_id]' => $parentId,
            ],
        ];

        foreach ($variants as $variant) {
            try {
                $response = $this->client->get('/Api/V8/module/' . $module, $common + $variant);
            } catch (SuiteCrmHttpException $e) {
                if ($e->getStatus() === 400) {
                    continue;
                }
                throw $e;
            } catch (SuiteCrmException) {
                return [];
            }

            $data = $response['data'] ?? null;
            if (is_array($data)) {
                return $data;
            }
        }

        return [];
    }

    private function mapTimelineEntry(string $module, array $item, array $config): ?array
    {
        $id = isset($item['id']) ? trim((string) $item['id']) : '';
        if ($id === '') {
            return null;
        }

        $attributes = $item['attributes'] ?? null;
        if (!is_array($attributes)) {
            return null;
        }

        $occurredField = (string) ($config['occurredField'] ?? '');
        $occurredRaw = trim((string) ($attributes[$occurredField] ?? $attributes['date_entered'] ?? ''));
        $occurredAt = $this->normalizeDateTime($occurredRaw);
        if ($occurredAt === null) {
            return null;
        }

        $title = trim((string) ($attributes['name'] ?? ''));
        if ($title === '') {
            $title = $module . ' ' . $id;
        }

        $summaryFields = isset($config['summaryFields']) && is_array($config['summaryFields'])
            ? $config['summaryFields']
            : [];
        $summary = '';
        foreach ($summaryFields as $fieldName) {
            $field = trim((string) $fieldName);
            if ($field === '') {
                continue;
            }
            $value = trim((string) ($attributes[$field] ?? ''));
            if ($value !== '') {
                $summary = $value;
                break;
            }
        }

        $type = trim((string) ($config['type'] ?? $module));
        if ($type === '') {
            $type = $module;
        }

        return [
            'type' => $type,
            'occurredAt' => $occurredAt,
            'title' => $this->toShortText($title, 255),
            'summary' => $summary !== '' ? $this->toShortText($summary, 240) : null,
            'link' => $this->profile->deepLink($module, $id),
        ];
    }

    private function timelineModuleConfigs(): array
    {
        return [
            'Notes' => [
                'type' => 'Note',
                'occurredField' => 'date_entered',
                'summaryFields' => ['description'],
            ],
            'Calls' => [
                'type' => 'Call',
                'occurredField' => 'date_start',
                'summaryFields' => ['status', 'direction', 'description'],
            ],
            'Meetings' => [
                'type' => 'Meeting',
                'occurredField' => 'date_start',
                'summaryFields' => ['status', 'description'],
            ],
            'Tasks' => [
                'type' => 'Task',
                'occurredField' => 'date_due',
                'summaryFields' => ['status', 'description'],
            ],
        ];
    }

    private function normalizeDateTime(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return gmdate('c', $timestamp);
    }

    private function persistAttachmentRecords(
        array $attachments,
        string $messageKey,
        string $linkModule,
        string $linkId,
        string $noteId,
        ?int $maxAttachmentBytes
    ): void {
        foreach ($attachments as $rawAttachment) {
            if (!is_array($rawAttachment)) {
                continue;
            }

            $attachment = $this->normalizeAttachment($rawAttachment, $maxAttachmentBytes);
            if ($attachment === null) {
                continue;
            }

            $persisted = false;
            try {
                $persisted = $this->persistAttachmentAsDocument(
                    $attachment,
                    $messageKey,
                    $noteId
                );
            } catch (SuiteCrmException $e) {
                $this->log('Attachment document persistence failed: ' . $e->getMessage());
            }

            if ($persisted) {
                continue;
            }

            try {
                $this->persistAttachmentAsNoteFallback(
                    $attachment,
                    $noteId,
                    $linkModule,
                    $linkId
                );
            } catch (SuiteCrmException $e) {
                $this->log('Attachment note fallback failed: ' . $e->getMessage());
            }
        }
    }

    private function persistAttachmentAsDocument(
        array $attachment,
        string $messageKey,
        string $noteId
    ): bool {
        $attributes = [
            'document_name' => $this->toShortText((string) $attachment['name'], 255),
            'filename' => $this->toShortText((string) $attachment['name'], 255),
            'description' => $this->toShortText(
                'Created by SuiteSidecar attachment persistence. messageKey=' . $messageKey . ' profile=' . $this->profile->id,
                255
            ),
            'parent_type' => 'Notes',
            'parent_id' => $noteId,
        ];

        $contentType = trim((string) ($attachment['contentType'] ?? ''));
        if ($contentType !== '') {
            $attributes['file_mime_type'] = $contentType;
        }

        $contentBase64 = trim((string) ($attachment['contentBase64'] ?? ''));
        if ($contentBase64 !== '') {
            $attributes['filecontents'] = $contentBase64;
        }

        try {
            $response = $this->client->post('/Api/V8/module', [
                'data' => [
                    'type' => 'Documents',
                    'attributes' => $attributes,
                ],
            ]);
        } catch (SuiteCrmHttpException $e) {
            if (in_array($e->getStatus(), [400, 404], true)) {
                return false;
            }
            throw $e;
        }

        $item = $response['data'] ?? null;
        return is_array($item) && trim((string) ($item['id'] ?? '')) !== '';
    }

    private function persistAttachmentAsNoteFallback(
        array $attachment,
        string $noteId,
        string $linkModule,
        string $linkId
    ): void {
        $name = (string) ($attachment['name'] ?? 'attachment');
        $sizeBytes = (int) ($attachment['sizeBytes'] ?? 0);
        $contentType = trim((string) ($attachment['contentType'] ?? ''));
        $linkTarget = trim($linkModule) !== '' && trim($linkId) !== ''
            ? sprintf('%s:%s', $linkModule, $linkId)
            : 'unknown';

        $description = sprintf(
            'Attachment metadata stored by SuiteSidecar. sourceNoteId=%s linkedTo=%s sizeBytes=%d mime=%s',
            $noteId,
            $linkTarget,
            $sizeBytes,
            $contentType !== '' ? $contentType : 'unknown'
        );

        $attributes = [
            'name' => $this->toShortText('Attachment: ' . $name, 255),
            'description' => $description,
            'parent_type' => 'Notes',
            'parent_id' => $noteId,
            'filename' => $this->toShortText($name, 255),
        ];

        if ($contentType !== '') {
            $attributes['file_mime_type'] = $contentType;
        }

        $contentBase64 = trim((string) ($attachment['contentBase64'] ?? ''));
        if ($contentBase64 !== '') {
            $attributes['filecontents'] = $contentBase64;
        }

        try {
            $this->client->post('/Api/V8/module', [
                'data' => [
                    'type' => 'Notes',
                    'attributes' => $attributes,
                ],
            ]);
            return;
        } catch (SuiteCrmHttpException $e) {
            if (!in_array($e->getStatus(), [400, 404], true)) {
                throw $e;
            }
        }

        if (trim($linkModule) === '' || trim($linkId) === '') {
            return;
        }

        $attributes['parent_type'] = $linkModule;
        $attributes['parent_id'] = $linkId;

        $this->client->post('/Api/V8/module', [
            'data' => [
                'type' => 'Notes',
                'attributes' => $attributes,
            ],
        ]);
    }

    private function normalizeAttachment(array $attachment, ?int $maxAttachmentBytes): ?array
    {
        $name = trim((string) ($attachment['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $sizeBytes = $this->toPositiveIntOrNull($attachment['sizeBytes'] ?? null);
        $contentBase64 = trim((string) ($attachment['contentBase64'] ?? ''));
        if ($sizeBytes === null && $contentBase64 !== '') {
            $sizeBytes = max(1, (int) floor((strlen($contentBase64) * 3) / 4));
        }

        if ($maxAttachmentBytes !== null && $sizeBytes !== null && $sizeBytes > $maxAttachmentBytes) {
            return null;
        }

        return [
            'name' => $name,
            'sizeBytes' => $sizeBytes ?? 0,
            'contentType' => isset($attachment['contentType']) ? (string) $attachment['contentType'] : null,
            'contentBase64' => $contentBase64 !== '' ? $contentBase64 : null,
        ];
    }

    private function toPositiveIntOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_float($value)) {
            $parsed = (int) round($value);
            if ($parsed > 0 && abs($value - $parsed) < 0.0001) {
                return $parsed;
            }
            return null;
        }

        if (is_string($value) && trim($value) !== '') {
            $parsed = filter_var($value, FILTER_VALIDATE_INT);
            if (is_int($parsed) && $parsed > 0) {
                return $parsed;
            }
        }

        return null;
    }

    private function toShortText(string $value, int $limit): string
    {
        return substr(trim($value), 0, $limit);
    }

    private function log(string $message): void
    {
        error_log($message);
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
