# Phase 1 Deeplinks: Create Call / Create Meeting

## Scope
- Add-in actions: `Create Call`, `Create Meeting`
- Backend impact: minimal, only enrich lookup response with action links
- No SuiteCRM instance customization required

## Current deeplink pattern (existing)
- Add-in does not build CRM record URLs itself.
- Connector returns canonical links in lookup payload (`match.person.link`), and add-in renders them with `target="_blank"`.
- URL base is profile-scoped from connector config (`suitecrmBaseUrl`):
  - DEV example: `example-dev -> https://crmdev.tapsa.duckdns.org`
  - PROD example: `evomax-prod -> https://crm.evomax.fi`
- Existing record deeplink convention remains:
  - `https://<crm>/#/module/record/<id>`

## New deeplink formats
Connector now returns:
- `match.actions.createCallLink`
- `match.actions.createMeetingLink`

Format:
- Call:
  - `https://<crm>/legacy/index.php?module=Calls&action=EditView&return_module=<Contacts|Leads>&return_action=DetailView&return_id=<personId>&parent_type=<Contacts|Leads>&parent_id=<personId>&parent_name=<displayName>`
- Meeting:
  - `https://<crm>/legacy/index.php?module=Meetings&action=EditView&return_module=<Contacts|Leads>&return_action=DetailView&return_id=<personId>&parent_type=<Contacts|Leads>&parent_id=<personId>&parent_name=<displayName>`

## SuiteCRM prefill mechanism verification
Verified in SuiteCRM DEV codebase:
- Calls and Meetings beans read `parent_type` from request in create/edit flow.
- Save form handling adds parent Contact/Lead relation from `parent_type` + `parent_id`.

Relevant legacy sources checked:
- `public/legacy/modules/Calls/Call.php`
- `public/legacy/modules/Calls/CallFormBase.php`
- `public/legacy/modules/Meetings/Meeting.php`
- `public/legacy/modules/Meetings/MeetingFormBase.php`

Conclusion:
- URL prefill is supported for Calls/Meetings using `parent_type` + `parent_id` (+ `parent_name` for visible prefill context).
- No custom SuiteCRM entrypoint/controller needed for this phase.

## Security notes
- Only CRM record IDs and module names are put in URL parameters.
- No email body/subject content is leaked into deeplink query strings.
- Links are generated server-side from authenticated profile context.
- CRM session + ACL still enforce access on create forms and related records.

## Manual test checklist (Phase 1)
1. Login in add-in with `example-dev` profile.
2. Open email where sender resolves to Contact.
3. Click `Create Call`:
   - CRM opens in new tab.
   - Calls create form opens.
   - Contact relation appears prefilled before save.
4. Click `Create Meeting` and verify same behavior.
5. Repeat with sender resolving to Lead and verify relation uses Lead.
6. Open email with no match:
   - `Create Call` and `Create Meeting` are disabled.
7. Confirm generated links contain only IDs/module metadata, not email body text.

## Screenshot notes (manual)
- `Phase1-1`: Lookup found (Contact) with `Create Call`/`Create Meeting` enabled.
- `Phase1-2`: Calls create form with prefilled related Contact.
- `Phase1-3`: Meetings create form with prefilled related Contact.
- `Phase1-4`: No-match state with buttons disabled.
