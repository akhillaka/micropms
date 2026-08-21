/**
 * Shared fetch wrapper, loading helpers, and IndexedDB offline queue.
 * Used by admin pages and the Assistant PWA.
 */
(function (global) {
  'use strict';

  const DB_NAME = 'pms_offline';
  const DB_VERSION = 1;
  const STORE = 'queue';

  class ApiError extends Error {
    constructor(message, extras) {
      super(message || 'Request failed');
      this.name = 'ApiError';
      this.status = extras.status || 0;
      this.code = extras.code || 'UNKNOWN';
      this.retryable = !!extras.retryable;
      this.fieldErrors = extras.fieldErrors || [];
      this.data = extras.data || null;
      this.queued = !!extras.queued;
    }
  }

  function escapeHtml(str) {
    return String(str == null ? '' : str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function getCsrf(explicit) {
    if (explicit) return explicit;
    if (global.__PMS_CSRF) return global.__PMS_CSRF;
    if (typeof CSRF_TOKEN !== 'undefined' && CSRF_TOKEN) return CSRF_TOKEN;
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? (meta.getAttribute('content') || meta.content || '') : '';
  }

  function messageForStatus(status, data) {
    const nested = data && data.error && data.error.message;
    if (nested) return nested;
    if (data && data.message) return data.message;
    if (status === 400) return 'Please check the highlighted fields.';
    if (status === 403) return "You don't have permission to do that.";
    if (status === 409) return 'Room no longer available — refresh and try again.';
    if (status === 419) return 'Session expired. Please sign in again.';
    if (status === 429) return 'Too many requests — wait a moment and retry.';
    if (status >= 500) return 'Server error. Please retry.';
    if (status === 0) return 'No internet connection.';
    return 'Request failed';
  }

  function toastFromError(err, retryFn) {
    const status = err && err.status;
    let type = 'error';
    if (status === 409 || status === 429 || status === 400) type = 'warning';
    if (err && err.queued) type = 'info';
    const msg = (err && err.message) || 'Request failed';
    const duration = retryFn ? 8000 : 4200;
    if (typeof showToast === 'function') {
      showToast(msg, type === 'error' ? 'error' : type, duration);
    } else if (global.UI && typeof UI.showToast === 'function') {
      UI.showToast(msg, type);
    }
    return err;
  }

  function applyFieldErrors(fieldErrors, form) {
    if (!form || !Array.isArray(fieldErrors) || fieldErrors.length === 0) return;
    fieldErrors.forEach((item) => {
      const name = item.field || item.name;
      if (!name) return;
      const el = form.querySelector(`[name="${CSS.escape ? CSS.escape(name) : name}"]`);
      if (!el) return;
      el.classList.add('pms-field-error');
      let hint = el.parentElement && el.parentElement.querySelector('.pms-field-error-msg');
      if (!hint) {
        hint = document.createElement('p');
        hint.className = 'pms-field-error-msg';
        el.parentElement && el.parentElement.appendChild(hint);
      }
      hint.textContent = item.message || 'Invalid value';
    });
  }

  function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
  }

  function parseJsonSafe(res) {
    return res.text().then((text) => {
      if (!text) return {};
      try {
        return JSON.parse(text);
      } catch (e) {
        const m = text.match(/\{[\s\S]*\}/);
        if (m) {
          try { return JSON.parse(m[0]); } catch (e2) { /* ignore */ }
        }
        return { message: text.slice(0, 200) };
      }
    });
  }

  function isNetworkError(err) {
    return err instanceof TypeError || (err && err.name === 'TypeError');
  }

  function shouldRetry(attempt, maxRetries, err, method) {
    if (attempt >= maxRetries) return false;
    if (!err) return false;
    if (err.retryable === false) return false;
    if (err.queued) return false;
    if (err.status === 429 || err.status === 502 || err.status === 503 || err.status === 504) return true;
    if (err.status >= 500 && method === 'GET') return true;
    if (err.status === 0) return true;
    return false;
  }

  function openDb() {
    return new Promise((resolve, reject) => {
      if (!global.indexedDB) {
        reject(new Error('IndexedDB unavailable'));
        return;
      }
      const req = indexedDB.open(DB_NAME, DB_VERSION);
      req.onupgradeneeded = () => {
        const db = req.result;
        if (!db.objectStoreNames.contains(STORE)) {
          const store = db.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true });
          store.createIndex('createdAt', 'createdAt', { unique: false });
        }
      };
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error || new Error('IndexedDB open failed'));
    });
  }

  function idbOp(mode, fn) {
    return openDb().then((db) => new Promise((resolve, reject) => {
      const tx = db.transaction(STORE, mode);
      const store = tx.objectStore(STORE);
      const result = fn(store);
      tx.oncomplete = () => {
        db.close();
        resolve(result);
      };
      tx.onerror = () => {
        db.close();
        reject(tx.error);
      };
    }));
  }

  const offlineQueue = {
    async push(job) {
      const record = {
        url: job.url,
        method: (job.method || 'POST').toUpperCase(),
        body: job.body || '',
        headers: job.headers || {},
        createdAt: Date.now(),
        attempts: 0,
        kind: job.kind || 'json',
        label: job.label || 'Pending request',
        clientId: job.clientId || '',
        idempotencyKey: job.idempotencyKey || (global.crypto && crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + '-' + Math.random().toString(16).slice(2))
      };
      const db = await openDb();
      return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE, 'readwrite');
        const req = tx.objectStore(STORE).add(record);
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
        tx.oncomplete = () => db.close();
        tx.onerror = () => { db.close(); reject(tx.error); };
      });
    },

    async all() {
      const db = await openDb();
      return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE, 'readonly');
        const req = tx.objectStore(STORE).getAll();
        req.onsuccess = () => resolve(req.result || []);
        req.onerror = () => reject(req.error);
        tx.oncomplete = () => db.close();
        tx.onerror = () => { db.close(); reject(tx.error); };
      });
    },

    async remove(id) {
      return idbOp('readwrite', (store) => store.delete(id));
    },

    async put(job) {
      return idbOp('readwrite', (store) => store.put(job));
    },

    async count() {
      const db = await openDb();
      return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE, 'readonly');
        const req = tx.objectStore(STORE).count();
        req.onsuccess = () => resolve(req.result || 0);
        req.onerror = () => reject(req.error);
        tx.oncomplete = () => db.close();
        tx.onerror = () => { db.close(); reject(tx.error); };
      });
    },

    async rewriteGuestId(offlineId, realId) {
      if (!offlineId || !realId) return;
      const jobs = await this.all();
      for (const job of jobs) {
        if (!job.body || typeof job.body !== 'string') continue;
        if (job.body.indexOf(String(offlineId)) === -1) continue;
        job.body = job.body.split(String(offlineId)).join(String(realId));
        await this.put(job);
      }
    },

    async flushWhenOnline() {
      if (!navigator.onLine) return { flushed: 0, remaining: await this.count().catch(() => 0) };
      if (this._flushing) return this._flushing;
      this._flushing = this._flush().finally(() => { this._flushing = null; });
      return this._flushing;
    },

    async _flush() {
      let flushed = 0;
      const jobs = await this.all();
      jobs.sort((a, b) => (a.id || 0) - (b.id || 0));
      for (const job of jobs) {
        if (!navigator.onLine) break;
        try {
          const headers = Object.assign({
            'Accept': 'application/json',
            'Content-Type': 'application/json'
          }, job.headers || {});
          const csrf = getCsrf();
          if (csrf) {
            headers['X-CSRF-TOKEN'] = csrf;
            headers['X-CSRF-Token'] = csrf;
          }
          if (job.idempotencyKey) headers['X-Idempotency-Key'] = job.idempotencyKey;
          const res = await fetch(job.url, {
            method: job.method || 'POST',
            headers: headers,
            body: job.body,
            credentials: 'same-origin',
            cache: 'no-store'
          });
          const data = await parseJsonSafe(res);
          if (!res.ok) {
            const retryable = !!(data.error && data.error.retryable) || res.status >= 500 || res.status === 429;
            if (!retryable) {
              await this.remove(job.id);
              continue;
            }
            job.attempts = (job.attempts || 0) + 1;
            await this.put(job);
            break;
          }
          if (job.clientId && data && data.guest_id) {
            await this.rewriteGuestId(job.clientId, data.guest_id);
            for (let i = 0; i < jobs.length; i++) {
              const later = jobs[i];
              if (!later || later.id === job.id || typeof later.body !== 'string') continue;
              if (later.body.indexOf(String(job.clientId)) === -1) continue;
              later.body = later.body.split(String(job.clientId)).join(String(data.guest_id));
            }
          }
          await this.remove(job.id);
          flushed += 1;
        } catch (e) {
          job.attempts = (job.attempts || 0) + 1;
          try { await this.put(job); } catch (e2) { /* ignore */ }
          break;
        }
      }
      const remaining = await this.count().catch(() => 0);
      if (flushed > 0 && typeof showToast === 'function') {
        showToast(remaining ? `Synced ${flushed} offline action(s). ${remaining} still pending.` : `Synced ${flushed} offline action(s).`, 'success');
      }
      document.dispatchEvent(new CustomEvent('pms:offline-queue', { detail: { flushed, remaining } }));
      return { flushed, remaining };
    }
  };

  async function apiFetch(url, options) {
    options = options || {};
    const method = (options.method || (options.body ? 'POST' : 'GET')).toUpperCase();
    const maxRetries = options.retryable === false ? 0 : (options.maxRetries != null ? options.maxRetries : (method === 'GET' ? 2 : 1));
    const csrf = getCsrf(options.csrfToken);
    const headers = Object.assign({
      'Accept': 'application/json'
    }, options.headers || {});

    const isForm = (typeof FormData !== 'undefined') && options.body instanceof FormData;
    if (!isForm && options.body && !headers['Content-Type'] && !headers['content-type']) {
      headers['Content-Type'] = 'application/json';
    }
    if (csrf) {
      headers['X-CSRF-TOKEN'] = headers['X-CSRF-TOKEN'] || csrf;
      headers['X-CSRF-Token'] = headers['X-CSRF-Token'] || csrf;
    }

    const fetchOpts = Object.assign({}, options, {
      method: method,
      headers: headers,
      credentials: options.credentials || 'same-origin',
      cache: options.cache || 'no-store'
    });
    delete fetchOpts.retryable;
    delete fetchOpts.maxRetries;
    delete fetchOpts.queueOffline;
    delete fetchOpts.csrfToken;
    delete fetchOpts.queueKind;
    delete fetchOpts.queueLabel;
    delete fetchOpts.clientId;
    delete fetchOpts.toast;
    delete fetchOpts.timeoutMs;

    const timeoutMs = options.timeoutMs != null ? options.timeoutMs : 30000;

    let lastErr = null;
    for (let attempt = 0; attempt <= maxRetries; attempt++) {
      const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
      let timeoutId = null;
      try {
        if (!navigator.onLine && options.queueOffline && method !== 'GET') {
          throw new ApiError('No internet connection.', { status: 0, code: 'OFFLINE', retryable: true });
        }
        const reqOpts = Object.assign({}, fetchOpts);
        if (controller) {
          reqOpts.signal = controller.signal;
          timeoutId = setTimeout(function () { controller.abort(); }, timeoutMs);
        }
        const res = await fetch(url, reqOpts);
        if (timeoutId) clearTimeout(timeoutId);
        const data = await parseJsonSafe(res);
        if (!res.ok) {
          const errInfo = (data && data.error) || {};
          lastErr = new ApiError(messageForStatus(res.status, data), {
            status: res.status,
            code: errInfo.code || 'HTTP_' + res.status,
            retryable: errInfo.retryable != null ? !!errInfo.retryable : (res.status >= 500 || res.status === 429),
            fieldErrors: errInfo.field_errors || [],
            data: data
          });
          if (shouldRetry(attempt, maxRetries, lastErr, method)) {
            await sleep(500 * (attempt + 1));
            continue;
          }
          if (options.toast !== false) toastFromError(lastErr);
          throw lastErr;
        }
        return data;
      } catch (e) {
        if (timeoutId) clearTimeout(timeoutId);
        if (e && e.name === 'AbortError') {
          lastErr = new ApiError('Request timed out. The server may be busy — try again.', {
            status: 0,
            code: 'TIMEOUT',
            retryable: true
          });
          if (shouldRetry(attempt, maxRetries, lastErr, method)) {
            await sleep(500 * (attempt + 1));
            continue;
          }
          if (options.toast !== false) toastFromError(lastErr);
          throw lastErr;
        }
        if (e instanceof ApiError && !e.retryable && e.status) throw e;
        lastErr = e instanceof ApiError ? e : new ApiError(e.message || 'Network error', {
          status: 0,
          code: 'NETWORK',
          retryable: true
        });
        const offline = !navigator.onLine || lastErr.status === 0 || isNetworkError(e);
        if (offline && options.queueOffline && method !== 'GET') {
          try {
            const body = typeof fetchOpts.body === 'string' ? fetchOpts.body : JSON.stringify(fetchOpts.body || {});
            await offlineQueue.push({
              url: url,
              method: method,
              body: body,
              headers: { 'Content-Type': 'application/json' },
              kind: options.queueKind || 'json',
              label: options.queueLabel || method + ' ' + url,
              clientId: options.clientId || ''
            });
            const queued = new ApiError('Saved offline. Will sync when you are back online.', {
              status: 0,
              code: 'QUEUED',
              retryable: false,
              queued: true
            });
            if (options.toast !== false) toastFromError(queued);
            throw queued;
          } catch (queueErr) {
            if (queueErr instanceof ApiError && queueErr.queued) throw queueErr;
          }
        }
        if (shouldRetry(attempt, maxRetries, lastErr, method)) {
          await sleep(500 * (attempt + 1));
          continue;
        }
        if (options.toast !== false && !(lastErr instanceof ApiError && lastErr.queued)) {
          toastFromError(lastErr);
        }
        throw lastErr;
      }
    }
    throw lastErr || new ApiError('Request failed', { status: 0, code: 'UNKNOWN' });
  }

  function withButtonLoading(btn, promise, label) {
    if (!btn) return Promise.resolve(promise);
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.setAttribute('aria-busy', 'true');
    btn.innerHTML = '<span class="pms-btn-spinner" aria-hidden="true"></span> ' + escapeHtml(label || 'Working…');
    return Promise.resolve(promise).finally(() => {
      btn.disabled = false;
      btn.removeAttribute('aria-busy');
      btn.innerHTML = orig;
    });
  }

  function showSkeleton(container, opts) {
    opts = opts || {};
    if (!container) return;
    const rows = opts.rows || 5;
    const type = opts.type || (container.tagName === 'TBODY' ? 'table' : 'block');
    if (type === 'kpi') {
      container.innerHTML = '<div class="skeleton h-6 w-1/2 mt-1"></div>';
      return;
    }
    if (type === 'table' || container.tagName === 'TBODY') {
      const colCount = opts.cols || (container.closest('table') && container.closest('table').querySelectorAll('thead th').length) || 5;
      const cell = '<td class="px-6 py-4"><div class="skeleton h-4 w-full"></div></td>';
      const row = '<tr>' + Array(colCount).fill(cell).join('') + '</tr>';
      container.innerHTML = Array(rows).fill(row).join('');
      return;
    }
    if (type === 'cards') {
      container.innerHTML = Array(rows).fill('<div class="skeleton h-16 w-full mb-3" style="height:4rem;margin-bottom:0.75rem;"></div>').join('');
      return;
    }
    container.innerHTML = Array(rows).fill('<div class="skeleton h-4 w-full mb-2" style="margin-bottom:0.5rem;"></div>').join('');
  }

  function showEmptyState(container, opts) {
    opts = opts || {};
    if (!container) return;
    const msg = opts.message || 'Nothing to show yet.';
    const retryHtml = opts.retryFn
      ? '<button type="button" class="pms-empty-retry" data-pms-retry>Retry</button>'
      : '';
    const icon = opts.icon || 'ph-tray';
    if (container.tagName === 'TBODY') {
      const cols = opts.colspan || (container.closest('table') && container.closest('table').querySelectorAll('thead th').length) || 1;
      container.innerHTML = '<tr><td colspan="' + cols + '" class="px-6 py-12 text-center text-slate-500">' +
        '<div class="pms-empty-state"><i class="ph ' + icon + '"></i><p>' + escapeHtml(msg) + '</p>' + retryHtml + '</div></td></tr>';
    } else {
      container.innerHTML = '<div class="pms-empty-state"><i class="ph ' + icon + '"></i><p>' + escapeHtml(msg) + '</p>' + retryHtml + '</div>';
    }
    const btn = container.querySelector('[data-pms-retry]');
    if (btn && typeof opts.retryFn === 'function') {
      btn.addEventListener('click', opts.retryFn);
    }
  }

  function bindOnlineFlush() {
    global.addEventListener('online', () => {
      offlineQueue.flushWhenOnline().catch(() => { /* ignore */ });
    });
    if (navigator.onLine) {
      setTimeout(() => offlineQueue.flushWhenOnline().catch(() => { /* ignore */ }), 800);
    }
  }

  bindOnlineFlush();

  global.ApiClient = {
    ApiError: ApiError,
    getCsrf: getCsrf,
    apiFetch: apiFetch,
    withButtonLoading: withButtonLoading,
    showSkeleton: showSkeleton,
    showEmptyState: showEmptyState,
    toastFromError: toastFromError,
    messageForStatus: messageForStatus,
    applyFieldErrors: applyFieldErrors,
    offlineQueue: offlineQueue,
    escapeHtml: escapeHtml
  };
  global.apiFetch = apiFetch;
  global.withButtonLoading = withButtonLoading;
})(window);
