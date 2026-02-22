'use strict';

const state = {
  token: '',
  tokenExpiresAt: '',
  profiles: [],
  lastLookup: null,
  lastOutlookBodyText: '',
  lastOutlookAttachments: [],
  lastOutlookContextKey: '',
  officeReady: false,
  itemChangedHandlerRegistered: false,
  autoLookupTimer: null,
  restoreProfileId: '',
};

const els = {};
const SESSION_STORAGE_KEY = 'suitesidecar.session';
const DEFAULT_CONNECTOR_BASE_URL = 'https://suitesidecar.example.com';

function initElements() {
  const ids = [
    'connectorBaseUrl',
    'loadProfilesBtn',
    'profileSelect',
    'usernameInput',
    'passwordInput',
    'loginBtn',
    'logoutBtn',
    'sessionInfo',
    'senderEmailInput',
    'subjectInput',
    'internetMessageIdInput',
    'recipientEmailsInput',
    'attachmentsInfo',
    'sentAtInput',
    'quickActionsBar',
    'lookupResult',
    'timelineResult',
    'toggleActionsBtn',
    'actionsBody',
    'firstNameInput',
    'lastNameInput',
    'titleInput',
    'companyInput',
    'linkModuleSelect',
    'linkIdInput',
    'storeBodyCheckbox',
    'storeAttachmentsCheckbox',
    'maxAttachmentBytesInput',
    'createContactBtn',
    'createLeadBtn',
    'logEmailBtn',
    'connectorCard',
    'loginCard',
    'selectedEmailCard',
    'lookupCard',
    'actionsCard',
    'sessionActionsRow',
    'statusLogoutBtn',
    'statusBox',
    'statusMessage',
    'statusRequestId',
  ];

  for (const id of ids) {
    els[id] = document.getElementById(id);
  }
}

function setStatus(kind, message, requestId = '') {
  if (!els.statusBox || !els.statusMessage || !els.statusRequestId) {
    return;
  }
  els.statusBox.className = `status status-${kind}`;
  els.statusMessage.textContent = message;
  els.statusRequestId.textContent = requestId ? `requestId: ${requestId}` : '';
}

function normalizeBaseUrl(value) {
  return String(value || '').trim().replace(/\/+$/, '');
}

function activeProfileId() {
  return String(els.profileSelect.value || '').trim();
}

function isTokenExpired() {
  if (!state.token || !state.tokenExpiresAt) {
    return false;
  }

  const expiresAtMs = Date.parse(state.tokenExpiresAt);
  if (Number.isNaN(expiresAtMs)) {
    return false;
  }

  return Date.now() >= expiresAtMs;
}

function ensureAuthenticated(actionLabel, options = {}) {
  const suppressStatus = options.suppressStatus === true;

  if (!state.token) {
    if (!suppressStatus) {
      setStatus('warning', `Login is required before ${actionLabel}.`);
    }
    return false;
  }

  if (isTokenExpired()) {
    clearSession();
    if (!suppressStatus) {
      setStatus('warning', `Session expired. Login again before ${actionLabel}.`);
    }
    return false;
  }

  if (!activeProfileId()) {
    if (!suppressStatus) {
      setStatus('warning', `Select profile before ${actionLabel}.`);
    }
    return false;
  }

  return true;
}

function availableStorages() {
  const storages = [];
  try {
    if (window.localStorage) {
      storages.push(window.localStorage);
    }
  } catch (error) {
    console.warn('localStorage unavailable', error);
  }
  try {
    if (window.sessionStorage) {
      storages.push(window.sessionStorage);
    }
  } catch (error) {
    console.warn('sessionStorage unavailable', error);
  }
  return storages;
}

function writeSessionSnapshot(rawValue) {
  for (const storage of availableStorages()) {
    try {
      storage.setItem(SESSION_STORAGE_KEY, rawValue);
    } catch (error) {
      console.warn('Failed to persist session snapshot', error);
    }
  }
}

function readSessionSnapshot() {
  const storages = availableStorages();
  if (!storages.length) {
    return '';
  }

  // Prefer durable localStorage entry, then sessionStorage as legacy fallback.
  for (const storage of storages) {
    try {
      const raw = storage.getItem(SESSION_STORAGE_KEY);
      if (typeof raw === 'string' && raw.trim()) {
        return raw;
      }
    } catch (error) {
      console.warn('Failed to read session snapshot', error);
    }
  }

  return '';
}

function removeSessionSnapshot() {
  for (const storage of availableStorages()) {
    try {
      storage.removeItem(SESSION_STORAGE_KEY);
    } catch (error) {
      console.warn('Failed to remove session snapshot', error);
    }
  }
}

function persistSession() {
  const payload = {
    token: state.token,
    tokenExpiresAt: state.tokenExpiresAt,
    baseUrl: normalizeBaseUrl(els.connectorBaseUrl.value),
    profileId: activeProfileId(),
    username: String(els.usernameInput.value || '').trim(),
  };
  writeSessionSnapshot(JSON.stringify(payload));
}

function restoreSession() {
  const raw = readSessionSnapshot();
  if (!raw) {
    return;
  }

  try {
    const parsed = JSON.parse(raw);
    if (parsed && typeof parsed === 'object') {
      if (typeof parsed.baseUrl === 'string' && parsed.baseUrl) {
        els.connectorBaseUrl.value = parsed.baseUrl;
      }
      if (typeof parsed.token === 'string') {
        state.token = parsed.token;
      }
      if (typeof parsed.tokenExpiresAt === 'string') {
        state.tokenExpiresAt = parsed.tokenExpiresAt;
      }
      if (typeof parsed.username === 'string' && parsed.username) {
        els.usernameInput.value = parsed.username;
      }
      state.restoreProfileId = typeof parsed.profileId === 'string' ? parsed.profileId : '';
      // Normalize/migrate any legacy snapshot format.
      persistSession();
    }
  } catch (error) {
    console.warn('Failed to restore session', error);
    removeSessionSnapshot();
  }
}

function clearSession(options = {}) {
  const dropStoredState = options.dropStoredState === true;
  state.token = '';
  state.tokenExpiresAt = '';
  if (dropStoredState) {
    removeSessionSnapshot();
  } else {
    persistSession();
  }
  updateSessionInfo();
}

function hasAuthenticatedUiSession() {
  return Boolean(state.token && !isTokenExpired() && activeProfileId());
}

function setVisible(element, visible) {
  if (!element) {
    return;
  }
  element.classList.toggle('is-hidden', !visible);
}

function refreshPanelVisibility() {
  const authenticated = hasAuthenticatedUiSession();
  setVisible(els.connectorCard, !authenticated);
  setVisible(els.loginCard, !authenticated);
  setVisible(els.selectedEmailCard, authenticated);
  setVisible(els.quickActionsBar, authenticated);
  setVisible(els.lookupCard, authenticated);
  setVisible(els.actionsCard, authenticated);
  setVisible(els.sessionActionsRow, authenticated);
}

function updateSessionInfo() {
  if (!state.token) {
    els.sessionInfo.textContent = 'Not authenticated.';
    refreshPanelVisibility();
    return;
  }
  const profileText = activeProfileId() || state.restoreProfileId || 'none';
  els.sessionInfo.textContent = `Authenticated. profile=${profileText} tokenExpiresAt=${state.tokenExpiresAt || 'unknown'}`;
  refreshPanelVisibility();
}

function parseJsonSafe(text) {
  if (!text || !String(text).trim()) {
    return null;
  }
  try {
    return JSON.parse(text);
  } catch {
    return null;
  }
}

async function apiRequest(path, options = {}) {
  const baseUrl = normalizeBaseUrl(els.connectorBaseUrl.value);
  if (!baseUrl) {
    throw new Error('Connector Base URL is required');
  }

  const method = (options.method || 'GET').toUpperCase();
  const url = new URL(path, `${baseUrl}/`);
  if (options.query && typeof options.query === 'object') {
    for (const [key, value] of Object.entries(options.query)) {
      if (value !== null && value !== undefined && String(value) !== '') {
        url.searchParams.set(key, String(value));
      }
    }
  }

  const headers = {
    Accept: 'application/json',
  };

  if (options.auth !== false && state.token) {
    headers.Authorization = `Bearer ${state.token}`;
  }

  const headerProfileId = String(options.profileId || '').trim();
  if (headerProfileId) {
    headers['X-SuiteSidecar-Profile'] = headerProfileId;
  }

  let body;
  if (options.body !== undefined) {
    headers['Content-Type'] = 'application/json';
    body = JSON.stringify(options.body);
  }

  const response = await fetch(url.toString(), {
    method,
    headers,
    body,
  });

  const text = await response.text();
  const payload = parseJsonSafe(text);
  const requestId =
    (payload && payload.error && payload.error.requestId) ||
    (payload && payload.requestId) ||
    response.headers.get('x-request-id') ||
    '';

  if (!response.ok) {
    const message =
      (payload && payload.error && payload.error.message) ||
      `HTTP ${response.status} ${response.statusText}`;
    if (response.status === 401 && options.auth !== false && state.token) {
      clearSession();
    }
    const error = new Error(message);
    error.status = response.status;
    error.payload = payload;
    error.requestId = requestId;
    throw error;
  }

  return { payload, requestId };
}

function renderProfiles() {
  const previous = state.restoreProfileId || activeProfileId();
  els.profileSelect.innerHTML = '';
  for (const profile of state.profiles) {
    const opt = document.createElement('option');
    opt.value = profile.id;
    opt.textContent = `${profile.name} (${profile.id})`;
    els.profileSelect.appendChild(opt);
  }
  if (previous) {
    els.profileSelect.value = previous;
  }
  if (!els.profileSelect.value && state.profiles.length) {
    els.profileSelect.value = state.profiles[0].id;
  }
  updateSessionInfo();
}

function splitName(displayName) {
  const clean = String(displayName || '').trim();
  if (!clean) {
    return { firstName: '', lastName: '' };
  }
  const parts = clean.split(/\s+/);
  if (parts.length === 1) {
    return { firstName: parts[0], lastName: '' };
  }
  return {
    firstName: parts[0],
    lastName: parts.slice(1).join(' '),
  };
}

function defaultLookupHintHtml() {
  return '<p class="hint">No lookup executed yet.</p>';
}

function defaultTimelineHintHtml() {
  return '<p class="hint">No timeline loaded.</p>';
}

function setActionsCollapsed(collapsed) {
  if (els.actionsCard) {
    els.actionsCard.classList.toggle('is-collapsed', collapsed);
  }
  if (els.toggleActionsBtn) {
    els.toggleActionsBtn.setAttribute('aria-expanded', String(!collapsed));
    els.toggleActionsBtn.textContent = collapsed ? 'Details' : 'Hide Details';
  }
}

function setCreateActionsVisible(visible) {
  setVisible(els.createContactBtn, visible);
  setVisible(els.createLeadBtn, visible);
}

function buildOutlookContextKey(context) {
  if (!context) {
    return '';
  }

  const sentAtIso = context.sentAt instanceof Date ? context.sentAt.toISOString() : '';
  return [context.messageId, context.senderEmail, context.subject, sentAtIso].join('|');
}

function resetActionsForContext(context) {
  const names = splitName(context && context.senderName ? context.senderName : '');

  state.lastLookup = null;
  state.lastOutlookBodyText = '';
  state.lastOutlookAttachments = [];
  els.lookupResult.innerHTML = defaultLookupHintHtml();
  if (els.timelineResult) {
    els.timelineResult.innerHTML = defaultTimelineHintHtml();
    setVisible(els.timelineResult, false);
  }
  setActionsCollapsed(true);
  setCreateActionsVisible(false);
  els.firstNameInput.value = names.firstName;
  els.lastNameInput.value = names.lastName;
  els.titleInput.value = '';
  els.companyInput.value = '';
  els.linkModuleSelect.value = 'Contacts';
  els.linkIdInput.value = '';
  if (els.attachmentsInfo) {
    els.attachmentsInfo.textContent = 'Attachments: none';
  }
}

function formatLookup(payload) {
  if (!payload || payload.notFound) {
    return '<p class="hint">No matching Contact/Lead found.</p>';
  }

  const person = payload.match && payload.match.person ? payload.match.person : null;
  if (!person) {
    return '<p class="hint">Lookup response missing person payload.</p>';
  }

  const rows = [
    ['Module', person.module || ''],
    ['ID', person.id || ''],
    ['Name', person.displayName || ''],
    ['Email', person.email || ''],
    ['Phone', person.phone || ''],
    ['Title', person.title || ''],
  ];

  if (person.link) {
    rows.push([
      'Link',
      `<a href="${escapeHtml(person.link)}" target="_blank" rel="noreferrer">Open in CRM</a>`,
      true,
    ]);
  }

  const htmlRows = rows
    .map(([key, value, isHtml]) => `<dt>${escapeHtml(key)}</dt><dd>${isHtml ? value : escapeHtml(value)}</dd>`)
    .join('');

  return `<dl class="person-grid">${htmlRows}</dl>`;
}

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function parseRecipientEmails(raw) {
  const emails = String(raw || '')
    .split(',')
    .map((x) => x.trim())
    .filter(Boolean);

  return emails.map((email) => ({ email }));
}

function readSafeValue(reader, fallbackValue) {
  try {
    const value = reader();
    return value === null || value === undefined ? fallbackValue : value;
  } catch (error) {
    console.warn('Office item property read failed:', error);
    return fallbackValue;
  }
}

function readSafeRecipients(item) {
  const toList = readSafeValue(() => item.to, []);
  if (!Array.isArray(toList)) {
    return '';
  }
  return toList
    .map((entry) => String(readSafeValue(() => entry.emailAddress, '') || '').trim())
    .filter(Boolean)
    .join(', ');
}

function formatTimeline(payload) {
  const timeline = payload && payload.match && Array.isArray(payload.match.timeline) ? payload.match.timeline : [];
  if (!timeline.length) {
    return '<p class="hint">No timeline entries returned.</p>';
  }

  const html = timeline
    .map((entry) => {
      const type = escapeHtml(entry && entry.type ? entry.type : 'Activity');
      const title = escapeHtml(entry && entry.title ? entry.title : '');
      const summary = escapeHtml(entry && entry.summary ? entry.summary : '');
      const occurredAt = escapeHtml(entry && entry.occurredAt ? String(entry.occurredAt) : '');
      const link = entry && entry.link ? String(entry.link) : '';
      const titleHtml = link
        ? `<a href="${escapeHtml(link)}" target="_blank" rel="noreferrer">${title || type}</a>`
        : `${title || type}`;

      return `<li>
        <span class="timeline-item-title">${type}</span>
        <span>${titleHtml}</span>
        ${occurredAt ? `<span class="timeline-item-time">${occurredAt}</span>` : ''}
        ${summary ? `<p class="timeline-item-summary">${summary}</p>` : ''}
      </li>`;
    })
    .join('');

  return `<ol class="timeline-list">${html}</ol>`;
}

function normalizeBodyText(value) {
  return String(value || '')
    .replace(/\r\n/g, '\n')
    .trim();
}

function toBase64Utf8(value) {
  return btoa(unescape(encodeURIComponent(String(value || ''))));
}

function toPositiveIntOrNull(value) {
  const parsed = Number.parseInt(String(value || '').trim(), 10);
  if (!Number.isFinite(parsed) || parsed <= 0) {
    return null;
  }
  return parsed;
}

function mapOutlookAttachmentMeta(raw) {
  const id = String((raw && raw.id) || '').trim();
  const name = String((raw && raw.name) || '').trim();
  const sizeRaw = Number(raw && raw.size);
  const sizeBytes = Number.isFinite(sizeRaw) && sizeRaw > 0 ? Math.floor(sizeRaw) : null;
  const contentType = String((raw && raw.contentType) || '').trim() || null;

  return {
    id,
    name,
    sizeBytes,
    contentType,
  };
}

function updateAttachmentsInfo(attachments) {
  if (!els.attachmentsInfo) {
    return;
  }

  if (!Array.isArray(attachments) || attachments.length === 0) {
    els.attachmentsInfo.textContent = 'Attachments: none';
    return;
  }

  const totalBytes = attachments.reduce((sum, attachment) => {
    const size = Number(attachment && attachment.sizeBytes);
    return sum + (Number.isFinite(size) ? size : 0);
  }, 0);

  els.attachmentsInfo.textContent = `Attachments: ${attachments.length} file(s), ${totalBytes} bytes total`;
}

function attachmentContentAsync(item, attachmentId) {
  return new Promise((resolve, reject) => {
    if (!item || typeof item.getAttachmentContentAsync !== 'function') {
      reject(new Error('Attachment content API not available in this host.'));
      return;
    }

    item.getAttachmentContentAsync(attachmentId, (result) => {
      const successStatus = window.Office && Office.AsyncResultStatus
        ? Office.AsyncResultStatus.Succeeded
        : 'succeeded';

      if (result && result.status === successStatus) {
        resolve(result.value || {});
        return;
      }

      const message =
        result && result.error && result.error.message
          ? result.error.message
          : 'unknown attachment read error';
      reject(new Error(message));
    });
  });
}

async function resolveAttachmentsForLog(maxAttachmentBytes) {
  if (!(window.Office && Office.context && Office.context.mailbox && Office.context.mailbox.item)) {
    return { attachments: [], skippedCount: 0 };
  }

  const item = Office.context.mailbox.item;
  const source = Array.isArray(item.attachments) ? item.attachments.map(mapOutlookAttachmentMeta) : [];
  if (!source.length) {
    return { attachments: [], skippedCount: 0 };
  }

  const output = [];
  let skippedCount = 0;

  for (const attachment of source) {
    if (!attachment.id || !attachment.name) {
      skippedCount += 1;
      continue;
    }

    if (maxAttachmentBytes !== null && attachment.sizeBytes !== null && attachment.sizeBytes > maxAttachmentBytes) {
      skippedCount += 1;
      continue;
    }

    try {
      const content = await attachmentContentAsync(item, attachment.id);
      const format = content && typeof content.format === 'string' ? content.format.toLowerCase() : '';
      let contentBase64 = null;

      if (typeof content.content === 'string' && content.content) {
        if (format === 'base64') {
          contentBase64 = content.content;
        } else if (format === 'text') {
          contentBase64 = toBase64Utf8(content.content);
        } else {
          skippedCount += 1;
          continue;
        }
      }

      output.push({
        name: attachment.name,
        sizeBytes: attachment.sizeBytes || 0,
        contentType: attachment.contentType,
        contentBase64,
      });
    } catch (error) {
      console.warn('Skipping attachment content', attachment.name, error);
      skippedCount += 1;
    }
  }

  return {
    attachments: output,
    skippedCount,
  };
}

async function readOutlookBodyText(item) {
  if (!item || !item.body || typeof item.body.getAsync !== 'function') {
    return '';
  }

  return new Promise((resolve) => {
    const coercionText = window.Office && Office.CoercionType && Office.CoercionType.Text
      ? Office.CoercionType.Text
      : 'text';
    const successStatus = window.Office && Office.AsyncResultStatus
      ? Office.AsyncResultStatus.Succeeded
      : 'succeeded';

    item.body.getAsync(coercionText, (result) => {
      if (result && result.status === successStatus) {
        resolve(normalizeBodyText(result.value));
        return;
      }

      const errorMessage =
        result && result.error && result.error.message
          ? result.error.message
          : 'unknown error';
      console.warn('Unable to read Outlook item body text:', errorMessage);
      resolve('');
    });
  });
}

async function resolveBodyTextForLog() {
  if (state.lastOutlookBodyText) {
    return state.lastOutlookBodyText;
  }

  if (!(window.Office && Office.context && Office.context.mailbox && Office.context.mailbox.item)) {
    return '';
  }

  const bodyText = await readOutlookBodyText(Office.context.mailbox.item);
  state.lastOutlookBodyText = bodyText || '';
  return state.lastOutlookBodyText;
}

async function loadProfiles(options = {}) {
  const suppressStatus = options.suppressStatus === true;

  try {
    if (!suppressStatus) {
      setStatus('info', 'Loading profiles...');
    }
    const result = await apiRequest('/profiles', { auth: false });
    state.profiles = Array.isArray(result.payload && result.payload.profiles) ? result.payload.profiles : [];
    renderProfiles();
    persistSession();
    if (!suppressStatus) {
      setStatus('success', `Loaded ${state.profiles.length} profile(s).`, result.requestId || '');
    }
    return result;
  } catch (error) {
    if (!suppressStatus) {
      setStatus('error', `Failed to load profiles: ${error.message}`, error.requestId || '');
    }
    return null;
  }
}

async function login() {
  const profileId = activeProfileId();
  const username = String(els.usernameInput.value || '').trim();
  const password = String(els.passwordInput.value || '');

  if (!profileId || !username || !password) {
    setStatus('warning', 'Profile, username and password are required for login.');
    return;
  }

  try {
    setStatus('info', 'Authenticating...');
    const result = await apiRequest('/auth/login', {
      method: 'POST',
      auth: false,
      body: {
        profileId,
        username,
        password,
      },
    });

    state.token = String(result.payload && result.payload.token ? result.payload.token : '');
    state.tokenExpiresAt = String(result.payload && result.payload.tokenExpiresAt ? result.payload.tokenExpiresAt : '');
    persistSession();
    updateSessionInfo();
    setStatus('success', 'Login successful.', result.requestId || '');

    if (state.officeReady) {
      scheduleAutoLookup('login');
    } else {
      Promise.resolve(runLookup({ suppressStatus: true })).catch(() => {});
    }
  } catch (error) {
    clearSession();
    setStatus('error', `Login failed: ${error.message}`, error.requestId || '');
  }
}

async function logout(options = {}) {
  const suppressStatus = options.suppressStatus === true;

  if (!state.token) {
    clearSession();
    if (!suppressStatus) {
      setStatus('info', 'No active session.');
    }
    return;
  }

  try {
    const result = await apiRequest('/auth/logout', {
      method: 'POST',
    });
    clearSession();
    if (!suppressStatus) {
      setStatus('success', 'Logged out.', result.requestId || '');
    }
  } catch (error) {
    clearSession();
    if (!suppressStatus) {
      if (error.status === 401) {
        setStatus('warning', 'Session was already invalid on server. Local session cleared.', error.requestId || '');
        return;
      }
      setStatus('warning', `Logout request failed: ${error.message}. Local session cleared.`, error.requestId || '');
    }
  }
}

function confirmAndLogout() {
  const shouldConfirm = typeof window !== 'undefined' && typeof window.confirm === 'function';
  if (shouldConfirm && !window.confirm('Log out from current SuiteSidecar session?')) {
    return;
  }
  void logout();
}

function buildLookupEmail() {
  const sender = String(els.senderEmailInput.value || '').trim();
  return sender;
}

async function runLookup(options = {}) {
  const suppressStatus = options.suppressStatus === true;
  setCreateActionsVisible(false);

  if (!ensureAuthenticated('lookup', { suppressStatus })) {
    return null;
  }

  const email = buildLookupEmail();
  if (!email) {
    if (!suppressStatus) {
      setStatus('warning', 'Sender email is required for lookup.');
    }
    return null;
  }

  const profileId = activeProfileId();

  try {
    const result = await apiRequest('/lookup/by-email', {
      method: 'GET',
      profileId,
      query: {
        profileId,
        email,
        include: 'account,timeline',
      },
    });

    state.lastLookup = result.payload;
    els.lookupResult.innerHTML = formatLookup(result.payload);
    if (els.timelineResult) {
      els.timelineResult.innerHTML = formatTimeline(result.payload);
      setVisible(els.timelineResult, true);
    }

    if (result.payload && result.payload.notFound) {
      setActionsCollapsed(false);
      setCreateActionsVisible(true);
    } else {
      setActionsCollapsed(true);
      setCreateActionsVisible(false);
    }

    if (result.payload && !result.payload.notFound && result.payload.match && result.payload.match.person) {
      const person = result.payload.match.person;
      els.linkModuleSelect.value = person.module || 'Contacts';
      els.linkIdInput.value = person.id || '';
      if (person.firstName) {
        els.firstNameInput.value = person.firstName;
      }
      if (person.lastName) {
        els.lastNameInput.value = person.lastName;
      }
      if (person.title) {
        els.titleInput.value = person.title;
      }
    }

    if (!suppressStatus) {
      setStatus('success', 'Lookup completed.', result.requestId || '');
    }
    return result;
  } catch (error) {
    setCreateActionsVisible(false);
    if (!suppressStatus) {
      setStatus('error', `Lookup failed: ${error.message}`, error.requestId || '');
    }
    return null;
  }
}

async function createContact() {
  if (!ensureAuthenticated('creating contact')) {
    return;
  }

  const profileId = activeProfileId();

  const payload = {
    firstName: String(els.firstNameInput.value || '').trim(),
    lastName: String(els.lastNameInput.value || '').trim(),
    email: String(els.senderEmailInput.value || '').trim(),
    title: String(els.titleInput.value || '').trim() || null,
  };

  try {
    const result = await apiRequest('/entities/contacts', {
      method: 'POST',
      profileId,
      query: { profileId },
      body: payload,
    });

    const rec = result.payload || {};
    els.linkModuleSelect.value = rec.module || 'Contacts';
    els.linkIdInput.value = rec.id || '';
    setStatus('success', `Contact created: ${rec.displayName || rec.id || 'ok'}`, result.requestId || '');
  } catch (error) {
    setStatus('error', `Create Contact failed: ${error.message}`, error.requestId || '');
  }
}

async function createLead() {
  if (!ensureAuthenticated('creating lead')) {
    return;
  }

  const profileId = activeProfileId();

  const payload = {
    firstName: String(els.firstNameInput.value || '').trim(),
    lastName: String(els.lastNameInput.value || '').trim(),
    email: String(els.senderEmailInput.value || '').trim(),
    title: String(els.titleInput.value || '').trim() || null,
    company: String(els.companyInput.value || '').trim() || null,
  };

  try {
    const result = await apiRequest('/entities/leads', {
      method: 'POST',
      profileId,
      query: { profileId },
      body: payload,
    });

    const rec = result.payload || {};
    els.linkModuleSelect.value = rec.module || 'Leads';
    els.linkIdInput.value = rec.id || '';
    setStatus('success', `Lead created: ${rec.displayName || rec.id || 'ok'}`, result.requestId || '');
  } catch (error) {
    setStatus('error', `Create Lead failed: ${error.message}`, error.requestId || '');
  }
}

async function logEmail() {
  if (!ensureAuthenticated('logging email')) {
    return;
  }

  const profileId = activeProfileId();

  const senderEmail = String(els.senderEmailInput.value || '').trim();
  const recipients = parseRecipientEmails(els.recipientEmailsInput.value);
  const messageId = String(els.internetMessageIdInput.value || '').trim();
  const subject = String(els.subjectInput.value || '').trim();
  const linkModule = String(els.linkModuleSelect.value || '').trim();
  const linkId = String(els.linkIdInput.value || '').trim();
  const storeBody = Boolean(els.storeBodyCheckbox && els.storeBodyCheckbox.checked);
  const storeAttachments = Boolean(els.storeAttachmentsCheckbox && els.storeAttachmentsCheckbox.checked);
  const maxAttachmentBytes = toPositiveIntOrNull(els.maxAttachmentBytesInput ? els.maxAttachmentBytesInput.value : '');
  const contextAttachmentCount = Array.isArray(state.lastOutlookAttachments) ? state.lastOutlookAttachments.length : 0;

  if (!senderEmail || !recipients.length || !messageId || !subject || !linkModule || !linkId) {
    setStatus('warning', 'Sender, recipients, message ID, subject, link module and link id are required.');
    return;
  }

  const sentAtRaw = String(els.sentAtInput.value || '').trim();
  const sentAt = sentAtRaw ? new Date(sentAtRaw).toISOString() : new Date().toISOString();
  const bodyText = storeBody ? await resolveBodyTextForLog() : '';
  const attachmentResult = storeAttachments
    ? await resolveAttachmentsForLog(maxAttachmentBytes)
    : { attachments: [], skippedCount: 0 };

  const payload = {
    message: {
      internetMessageId: messageId,
      subject,
      from: { email: senderEmail },
      to: recipients,
      sentAt,
      bodyText: bodyText || null,
      attachments: attachmentResult.attachments,
    },
    linkTo: {
      module: linkModule,
      id: linkId,
    },
    options: {
      storeBody,
      storeAttachments,
      maxAttachmentBytes,
    },
  };

  try {
    const result = await apiRequest('/email/log', {
      method: 'POST',
      profileId,
      query: { profileId },
      body: payload,
    });
    const rec = result.payload && result.payload.loggedRecord ? result.payload.loggedRecord : null;
    const attachmentStatus = storeAttachments
      ? ` Attachments sent=${attachmentResult.attachments.length}, skipped=${attachmentResult.skippedCount}.`
      : contextAttachmentCount > 0
        ? ` Attachments detected=${contextAttachmentCount}, but storage is disabled.`
        : '';
    setStatus('success', `Email logged: ${rec ? rec.id : 'ok'}.${attachmentStatus}`, result.requestId || '');
  } catch (error) {
    setStatus('error', `Log Email failed: ${error.message}`, error.requestId || '');
  }
}

async function readOutlookContext() {
  if (!(window.Office && Office.context && Office.context.mailbox && Office.context.mailbox.item)) {
    throw new Error('Office mailbox item is not available in this host.');
  }

  const item = Office.context.mailbox.item;

  const from = readSafeValue(() => item.from, {}) || {};
  const senderEmail = String(readSafeValue(() => from.emailAddress, '') || '').trim();
  const senderName = String(readSafeValue(() => from.displayName, '') || '').trim();

  const recipients = readSafeRecipients(item);

  const messageIdRaw = readSafeValue(() => item.internetMessageId, '');
  const messageId = typeof messageIdRaw === 'string' ? messageIdRaw : '';

  const subject = String(readSafeValue(() => item.subject, '') || '').trim();
  const sentAtDate = readSafeValue(() => item.dateTimeCreated, null) || readSafeValue(() => item.dateTimeSent, null);
  const sentAt = sentAtDate instanceof Date ? sentAtDate : new Date();
  const bodyText = await readOutlookBodyText(item);
  const rawAttachments = readSafeValue(() => item.attachments, []);
  const attachments = Array.isArray(rawAttachments) ? rawAttachments.map(mapOutlookAttachmentMeta) : [];

  return {
    senderEmail,
    senderName,
    recipients,
    messageId,
    subject,
    sentAt,
    bodyText,
    attachments,
  };
}

async function hydrateFromOutlook(options = {}) {
  const suppressStatus = options.suppressStatus === true;

  try {
    const context = await readOutlookContext();
    const contextKey = buildOutlookContextKey(context);
    const contextChanged = contextKey !== state.lastOutlookContextKey;

    if (contextChanged) {
      state.lastOutlookContextKey = contextKey;
      resetActionsForContext(context);
    }
    state.lastOutlookBodyText = context.bodyText || '';
    state.lastOutlookAttachments = Array.isArray(context.attachments) ? context.attachments : [];

    els.senderEmailInput.value = context.senderEmail;
    els.subjectInput.value = context.subject;
    els.internetMessageIdInput.value = context.messageId;
    els.recipientEmailsInput.value = context.recipients;
    els.sentAtInput.value = context.sentAt.toISOString().slice(0, 16);
    updateAttachmentsInfo(state.lastOutlookAttachments);

    if (!suppressStatus) {
      setStatus('success', 'Loaded selected email context from Outlook.');
    }
    return context;
  } catch (error) {
    if (!suppressStatus) {
      setStatus('warning', `Outlook context unavailable: ${error.message}`);
    }
    return null;
  }
}

async function tryAutoLookup(trigger) {
  const context = await hydrateFromOutlook({ suppressStatus: true });
  if (!context) {
    return;
  }

  if (!state.token) {
    setStatus('info', `Outlook item changed (${trigger}). Login to enable automatic lookup.`);
    return;
  }

  if (isTokenExpired()) {
    clearSession();
    setStatus('info', `Outlook item changed (${trigger}). Session expired; login to continue automatic lookup.`);
    return;
  }

  if (!activeProfileId()) {
    setStatus('warning', `Outlook item changed (${trigger}) but no profile is selected.`);
    return;
  }

  const result = await runLookup({ suppressStatus: true });
  if (result) {
    setStatus('success', `Automatic lookup completed (${trigger}).`, result.requestId || '');
    return;
  }
  if (!state.token) {
    setStatus('info', `Outlook item changed (${trigger}). Login to continue automatic lookup.`);
  }
}

function scheduleAutoLookup(trigger) {
  if (state.autoLookupTimer !== null) {
    clearTimeout(state.autoLookupTimer);
  }

  state.autoLookupTimer = setTimeout(() => {
    state.autoLookupTimer = null;
    Promise.resolve(tryAutoLookup(trigger)).catch((error) => {
      console.error('Auto lookup failed on item change', error);
      setStatus('warning', 'Automatic lookup failed after item change. You can continue manually.');
    });
  }, 350);
}

function registerItemChangedHandler() {
  if (!(window.Office && Office.context && Office.context.mailbox)) {
    return;
  }

  if (state.itemChangedHandlerRegistered) {
    return;
  }

  if (typeof Office.context.mailbox.addHandlerAsync !== 'function') {
    setStatus('warning', 'Office runtime does not support ItemChanged handlers in this host.');
    return;
  }

  Office.context.mailbox.addHandlerAsync(Office.EventType.ItemChanged, () => {
    try {
      scheduleAutoLookup('item_changed');
    } catch (error) {
      console.error('Failed to process ItemChanged event', error);
      setStatus('warning', 'Item change handling failed. Reopen the pane if needed.');
    }
  }, (result) => {
    if (result && result.status === Office.AsyncResultStatus.Succeeded) {
      state.itemChangedHandlerRegistered = true;
      if (!state.token) {
        setStatus('info', 'ItemChanged handler registered. Automatic lookup is ready.');
      }
      if (!(state.token && !activeProfileId())) {
        scheduleAutoLookup('initial');
      }
      return;
    }

    const errorMessage =
      result && result.error && result.error.message
        ? result.error.message
        : 'unknown registration failure';
    setStatus('warning', `ItemChanged handler registration failed: ${errorMessage}`);
  });
}

function wireEvents() {
  els.loadProfilesBtn.addEventListener('click', loadProfiles);
  els.loginBtn.addEventListener('click', login);
  els.logoutBtn.addEventListener('click', confirmAndLogout);
  els.createContactBtn.addEventListener('click', createContact);
  els.createLeadBtn.addEventListener('click', createLead);
  els.logEmailBtn.addEventListener('click', logEmail);
  if (els.toggleActionsBtn) {
    els.toggleActionsBtn.addEventListener('click', () => {
      const currentlyCollapsed = Boolean(els.actionsCard && els.actionsCard.classList.contains('is-collapsed'));
      setActionsCollapsed(!currentlyCollapsed);
    });
  }
  els.usernameInput.addEventListener('change', persistSession);
  if (els.statusLogoutBtn) {
    els.statusLogoutBtn.addEventListener('click', confirmAndLogout);
  }

  els.profileSelect.addEventListener('change', () => {
    if (state.token) {
      clearSession();
      setStatus('info', 'Profile changed. Login again for the selected profile.');
    }
    persistSession();
  });

  els.connectorBaseUrl.addEventListener('change', () => {
    if (state.token) {
      clearSession();
      setStatus('info', 'Connector URL changed. Login again.');
    }
    persistSession();
  });
}

async function restoreConnectionOnStartup() {
  const baseUrl = normalizeBaseUrl(els.connectorBaseUrl.value);
  if (!baseUrl || baseUrl === DEFAULT_CONNECTOR_BASE_URL) {
    setStatus('info', 'Ready. Configure connector, load profiles, then login.');
    return;
  }

  if (state.token && isTokenExpired()) {
    clearSession();
    setStatus('warning', 'Stored session token is expired. Login again.');
  }

  const profileResult = await loadProfiles({ suppressStatus: true });
  if (!profileResult) {
    if (state.token) {
      setStatus('warning', 'Stored session found, but automatic profile load failed. Check Connector URL.');
    } else {
      setStatus('warning', 'Automatic profile load failed. You can still retry manually.');
    }
    return;
  }

  if (state.token) {
    if (!hasAuthenticatedUiSession()) {
      clearSession();
      setStatus('info', 'Stored session is incomplete. Login to continue.');
      return;
    }
    updateSessionInfo();
    setStatus('info', 'Stored session restored. Authentication will be verified on first lookup.');
    if (state.officeReady) {
      scheduleAutoLookup('session_restore');
    }
    return;
  }

  setStatus('info', 'Profiles restored. Login to continue.');
}

function init() {
  initElements();
  restoreSession();
  wireEvents();
  updateSessionInfo();

  if (els.attachmentsInfo && !els.attachmentsInfo.textContent) {
    els.attachmentsInfo.textContent = 'Attachments: none';
  }
  if (els.timelineResult) {
    setVisible(els.timelineResult, false);
  }
  setActionsCollapsed(true);
  setCreateActionsVisible(false);

  if (!els.connectorBaseUrl.value) {
    els.connectorBaseUrl.value = DEFAULT_CONNECTOR_BASE_URL;
  }

  Promise.resolve(restoreConnectionOnStartup()).catch((error) => {
    console.error('Startup restore failed', error);
    setStatus('warning', 'Automatic restore failed. You can continue manually.');
  });

  if (window.Office && typeof Office.onReady === 'function') {
    Office.onReady(() => {
      state.officeReady = true;
      if (!state.token) {
        setStatus('info', 'Office runtime detected. Email context sync is active.');
      }
      registerItemChangedHandler();
    });
  }
}

window.addEventListener('DOMContentLoaded', init);
