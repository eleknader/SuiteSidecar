# Phase 2 API Features: Task From Email + Opportunities Panel

## 2A. Create Task From Email

### Endpoint
- `POST /tasks/from-email` (auth required, profile-scoped)

### Request contract (minimal metadata)
```json
{
  "message": {
    "graphMessageId": "AAMkAG...",
    "internetMessageId": "<abc123@example.com>",
    "subject": "Re: Service quote",
    "from": {
      "name": "Alex Sender",
      "email": "alex.sender@example.com"
    },
    "receivedDateTime": "2026-02-23T10:15:00Z",
    "conversationId": "AAQkAD...",
    "bodyPreview": "Short preview only",
    "webLink": "https://outlook.office.com/..."
  },
  "context": {
    "personModule": "Contacts",
    "personId": "b13a39f8-...",
    "accountId": "11a71596-..."
  }
}
```

### Response contract
- Created (`201`)
```json
{
  "task": {
    "module": "Tasks",
    "id": "3f4f...",
    "displayName": "Re: Service quote",
    "link": "https://crm.../#/tasks/record/3f4f..."
  },
  "deduplicated": false
}
```

- Deduplicated (`200`)
```json
{
  "task": {
    "module": "Tasks",
    "id": "3f4f...",
    "displayName": "Re: Service quote",
    "link": "https://crm.../#/tasks/record/3f4f..."
  },
  "deduplicated": true
}
```

### Data flow
1. Add-in sends minimal message metadata only (no full email body).
2. Connector validates payload and enriches audit metadata from authenticated session.
3. Connector dedup checks by message identifiers via local action-log index.
4. If duplicate exists and task still exists, return existing task (`200`).
5. Otherwise create Task in SuiteCRM (`201`) and persist dedup/audit mapping.
6. Relation resolution:
   - prefers provided context (`Contacts/Leads/Accounts`),
   - falls back to sender-email lookup (Contact first, then Lead),
   - allows unlinked task when no match exists.

### Security notes
- Stored in SuiteCRM Task: `subject`, sender, received time, optional short preview, message IDs, creator metadata.
- Full email body is not stored.
- Dedup keys are message IDs only.
- Endpoint is auth-required and profile-bound (`profileId` must match authenticated session).
- Auditability:
  - Connector records message-id -> task-id mapping with creator/timestamp metadata.
  - Task description includes provenance fields (who, when, message IDs).

### Manual tests
1. Same email, click `Create Task` twice -> second call returns `deduplicated=true` + same task link.
2. Unknown sender email -> task still created (unlinked) and opens correctly.
3. Confirm task description contains preview/link but no full body.

## 2B. Opportunities Panel

### Endpoint
- `GET /opportunities/by-context?personModule=Contacts&personId=<id>&accountId=<optional>&limit=5`

### Response contract
```json
{
  "items": [
    {
      "id": "opp-1",
      "name": "CNC Lathe Offer",
      "salesStage": "Prospecting",
      "amount": 12000,
      "currency": "-99",
      "dateClosed": "2026-03-15",
      "assignedUserName": "Demo User",
      "modifiedDate": "2026-02-23T08:01:00Z",
      "link": "https://crm.../#/opportunities/record/opp-1"
    }
  ],
  "viewAllLink": "https://crm.../#/accounts/record/account-1",
  "scope": {
    "mode": "account",
    "module": "Accounts",
    "id": "account-1"
  }
}
```

### Behavior
- Uses account opportunities when account context is available.
- Falls back to contact opportunities relation when no account context exists.
- Returns latest 5 opportunities by modified date.
- Returns context link for “View all” navigation.

### Security notes
- Read-only endpoint.
- Uses user-scoped SuiteCRM access token; ACL enforcement is delegated to SuiteCRM API.
- Returns minimal fields needed for panel rendering.

### Manual tests
1. Contact with account -> list shows up to 5 opportunities, links open CRM records.
2. Contact/lead without opportunities -> empty state rendered.
3. Click `View all` -> opens CRM context record page.
