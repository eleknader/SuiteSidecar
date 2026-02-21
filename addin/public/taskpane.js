'use strict';

const state = {
  token: '',
  tokenExpiresAt: '',
  profiles: [],
  lastLookup: null,
  officeReady: false,
  itemChangedHandlerRegistered: false,
  autoLookupTimer: null,
};

const els = {};

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
    'hydrateFromOutlookBtn',
    'senderEmailInput',
    'subjectInput',
    'internetMessageIdInput',
    'recipientEmailsInput',
    'sentAtInput',
    'lookupBtn',
    'lookupResult',
    'firstNameInput',
    'lastNameInput',
    'titleInput',
    'companyInput',
    'linkModuleSelect',
    'linkIdInput',
    'createContactBtn',
    'createLeadBtn',
    'logEmailBtn',
    'statusBox',
    'statusMessage',
    'statusRequestId',
  ];

  for (const id of ids) {
    els[id] = document.getElementById(id);
  }
}

function setStatus(kind, message, requestId = '') {
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

function persistSession() {
  const payload = {
    token: state.token,
    tokenExpiresAt: state.tokenExpiresAt,
    baseUrl: normalizeBaseUrl(els.connectorBaseUrl.value),
    profileId: activeProfileId(),
  };
  sessionStorage.setItem('suitesidecar.session', JSON.stringify(payload));
}

function restoreSession() {
  const raw = sessionStorage.getItem('suitesidecar.session');
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
      state.restoreProfileId = typeof parsed.profileId === 'string' ? parsed.profileId : '';
    }
  } catch (error) {
    console.warn('Failed to restore session', error);
  }
}

function clearSession() {
  state.token = '';
  state.tokenExpiresAt = '';
  sessionStorage.removeItem('suitesidecar.session');
  updateSessionInfo();
}

function updateSessionInfo() {
  if (!state.token) {
    els.sessionInfo.textContent = 'Not authenticated.';
    return;
  }
  els.sessionInfo.textContent = `Authenticated. tokenExpiresAt=${state.tokenExpiresAt || 'unknown'}`;
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
    rows.push(['Link', `<a href="${escapeHtml(person.link)}" target="_blank" rel="noreferrer">Open in CRM</a>`]);
  }

  const htmlRows = rows
    .map(([key, value]) => `<dt>${escapeHtml(key)}</dt><dd>${typeof value === 'string' ? escapeHtml(value) : value}</dd>`)
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

async function loadProfiles() {
  try {
    setStatus('info', 'Loading profiles...');
    const result = await apiRequest('/profiles', { auth: false });
    state.profiles = Array.isArray(result.payload && result.payload.profiles) ? result.payload.profiles : [];
    renderProfiles();
    persistSession();
    setStatus('success', `Loaded ${state.profiles.length} profile(s).`, result.requestId || '');
  } catch (error) {
    setStatus('error', `Failed to load profiles: ${error.message}`, error.requestId || '');
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
  } catch (error) {
    clearSession();
    setStatus('error', `Login failed: ${error.message}`, error.requestId || '');
  }
}

function buildLookupEmail() {
  const sender = String(els.senderEmailInput.value || '').trim();
  return sender;
}

async function runLookup(options = {}) {
  const suppressStatus = options.suppressStatus === true;

  if (!state.token) {
    if (!suppressStatus) {
      setStatus('warning', 'Login is required before lookup.');
    }
    return null;
  }

  const email = buildLookupEmail();
  if (!email) {
    if (!suppressStatus) {
      setStatus('warning', 'Sender email is required for lookup.');
    }
    return null;
  }

  try {
    const result = await apiRequest('/lookup/by-email', {
      method: 'GET',
      query: {
        profileId: activeProfileId(),
        email,
        include: 'account',
      },
    });

    state.lastLookup = result.payload;
    els.lookupResult.innerHTML = formatLookup(result.payload);

    if (result.payload && !result.payload.notFound && result.payload.match && result.payload.match.person) {
      const person = result.payload.match.person;
      els.linkModuleSelect.value = person.module || 'Contacts';
      els.linkIdInput.value = person.id || '';
      if (!els.firstNameInput.value && person.firstName) {
        els.firstNameInput.value = person.firstName;
      }
      if (!els.lastNameInput.value && person.lastName) {
        els.lastNameInput.value = person.lastName;
      }
    }

    if (!suppressStatus) {
      setStatus('success', 'Lookup completed.', result.requestId || '');
    }
    return result;
  } catch (error) {
    if (!suppressStatus) {
      setStatus('error', `Lookup failed: ${error.message}`, error.requestId || '');
    }
    return null;
  }
}

async function createContact() {
  if (!state.token) {
    setStatus('warning', 'Login is required before creating contact.');
    return;
  }

  const payload = {
    firstName: String(els.firstNameInput.value || '').trim(),
    lastName: String(els.lastNameInput.value || '').trim(),
    email: String(els.senderEmailInput.value || '').trim(),
    title: String(els.titleInput.value || '').trim() || null,
  };

  try {
    const result = await apiRequest('/entities/contacts', {
      method: 'POST',
      query: { profileId: activeProfileId() },
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
  if (!state.token) {
    setStatus('warning', 'Login is required before creating lead.');
    return;
  }

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
      query: { profileId: activeProfileId() },
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
  if (!state.token) {
    setStatus('warning', 'Login is required before log email.');
    return;
  }

  const senderEmail = String(els.senderEmailInput.value || '').trim();
  const recipients = parseRecipientEmails(els.recipientEmailsInput.value);
  const messageId = String(els.internetMessageIdInput.value || '').trim();
  const subject = String(els.subjectInput.value || '').trim();
  const linkModule = String(els.linkModuleSelect.value || '').trim();
  const linkId = String(els.linkIdInput.value || '').trim();

  if (!senderEmail || !recipients.length || !messageId || !subject || !linkModule || !linkId) {
    setStatus('warning', 'Sender, recipients, message ID, subject, link module and link id are required.');
    return;
  }

  const sentAtRaw = String(els.sentAtInput.value || '').trim();
  const sentAt = sentAtRaw ? new Date(sentAtRaw).toISOString() : new Date().toISOString();

  const payload = {
    message: {
      internetMessageId: messageId,
      subject,
      from: { email: senderEmail },
      to: recipients,
      sentAt,
      bodyText: null,
    },
    linkTo: {
      module: linkModule,
      id: linkId,
    },
  };

  try {
    const result = await apiRequest('/email/log', {
      method: 'POST',
      query: { profileId: activeProfileId() },
      body: payload,
    });
    const rec = result.payload && result.payload.loggedRecord ? result.payload.loggedRecord : null;
    setStatus('success', `Email logged: ${rec ? rec.id : 'ok'}`, result.requestId || '');
  } catch (error) {
    setStatus('error', `Log Email failed: ${error.message}`, error.requestId || '');
  }
}

async function readOutlookContext() {
  if (!(window.Office && Office.context && Office.context.mailbox && Office.context.mailbox.item)) {
    throw new Error('Office mailbox item is not available in this host.');
  }

  const item = Office.context.mailbox.item;

  const from = item.from || {};
  const senderEmail = String(from.emailAddress || '').trim();
  const senderName = String(from.displayName || '').trim();

  const recipients = Array.isArray(item.to)
    ? item.to
        .map((r) => String((r && r.emailAddress) || '').trim())
        .filter(Boolean)
        .join(', ')
    : '';

  let messageId = '';
  if (typeof item.internetMessageId === 'string') {
    messageId = item.internetMessageId;
  }

  const subject = String(item.subject || '').trim();
  const sentAtDate = item.dateTimeCreated || item.dateTimeSent || null;
  const sentAt = sentAtDate instanceof Date ? sentAtDate : new Date();

  return {
    senderEmail,
    senderName,
    recipients,
    messageId,
    subject,
    sentAt,
  };
}

async function hydrateFromOutlook(options = {}) {
  const suppressStatus = options.suppressStatus === true;

  try {
    const context = await readOutlookContext();

    els.senderEmailInput.value = context.senderEmail;
    els.subjectInput.value = context.subject;
    els.internetMessageIdInput.value = context.messageId;
    els.recipientEmailsInput.value = context.recipients;
    els.sentAtInput.value = context.sentAt.toISOString().slice(0, 16);

    if (context.senderName) {
      const names = splitName(context.senderName);
      if (!els.firstNameInput.value) {
        els.firstNameInput.value = names.firstName;
      }
      if (!els.lastNameInput.value) {
        els.lastNameInput.value = names.lastName;
      }
    }

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

  if (!activeProfileId()) {
    setStatus('warning', `Outlook item changed (${trigger}) but no profile is selected.`);
    return;
  }

  const result = await runLookup({ suppressStatus: true });
  if (result) {
    setStatus('success', `Automatic lookup completed (${trigger}).`, result.requestId || '');
  }
}

function scheduleAutoLookup(trigger) {
  if (state.autoLookupTimer !== null) {
    clearTimeout(state.autoLookupTimer);
  }

  state.autoLookupTimer = setTimeout(() => {
    state.autoLookupTimer = null;
    tryAutoLookup(trigger);
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
    scheduleAutoLookup('item_changed');
  }, (result) => {
    if (result && result.status === Office.AsyncResultStatus.Succeeded) {
      state.itemChangedHandlerRegistered = true;
      setStatus('info', 'ItemChanged handler registered. Automatic lookup is ready.');
      scheduleAutoLookup('initial');
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
  els.logoutBtn.addEventListener('click', () => {
    clearSession();
    setStatus('info', 'Session cleared.');
  });
  els.lookupBtn.addEventListener('click', runLookup);
  els.createContactBtn.addEventListener('click', createContact);
  els.createLeadBtn.addEventListener('click', createLead);
  els.logEmailBtn.addEventListener('click', logEmail);
  els.hydrateFromOutlookBtn.addEventListener('click', hydrateFromOutlook);

  els.profileSelect.addEventListener('change', persistSession);
  els.connectorBaseUrl.addEventListener('change', persistSession);
}

function init() {
  initElements();
  restoreSession();
  wireEvents();
  updateSessionInfo();

  if (!els.connectorBaseUrl.value) {
    els.connectorBaseUrl.value = 'https://connector.example.com';
  }

  setStatus('info', 'Ready. Load profiles, login, then run lookup/actions.');

  if (window.Office && typeof Office.onReady === 'function') {
    Office.onReady(() => {
      state.officeReady = true;
      setStatus('info', 'Office runtime detected. You can use selected email context.');
      registerItemChangedHandler();
    });
  }
}

window.addEventListener('DOMContentLoaded', init);
