(function (window, document) {
    'use strict';

    const SANITIZER_RULES = Object.freeze(JSON.parse('{"hydrationAttributes":["data-page","wire:snapshot","wire:initial-data"],"urlAttributes":["href","src","srcset","action","formaction","poster","data","cite","background","xlink:href","srcdoc"],"blockedTags":["canvas","video","audio","iframe","object","embed","source","track"],"maskedTags":["input","select","textarea"],"styleAttribute":"style","contentEditableAttribute":"contenteditable","maskText":"***"}'));
    const script = document.currentScript;
    const singletonKey = typeof Symbol === 'function'
        ? Symbol.for('artisan-build.reel.recorder')
        : '__artisanBuildReelRecorder__';
    const existing = window[singletonKey];

    if (existing) {
        window.Reel = existing.api;
        return;
    }

    const integer = (name, fallback) => {
        const value = Number.parseInt(script && script.dataset[name], 10);
        return Number.isFinite(value) && value > 0 ? value : fallback;
    };
    const config = Object.freeze({
        grantUrl: script && script.dataset.reelGrantUrl,
        reelUrl: script && script.dataset.reelUrl,
        csrfToken: script && script.dataset.reelCsrfToken,
        envelopeVersion: integer('reelEnvelopeVersion', 1),
        recorderVersion: (script && script.dataset.reelRecorderVersion) || '0.1.0',
        rrwebVersion: (script && script.dataset.reelRrwebVersion) || '2.1.1',
        compression: (script && script.dataset.reelCompression) || 'gzip',
        batchInterval: integer('reelBatchInterval', 10000),
        flushBytes: integer('reelFlushBytes', 65536),
        maxBufferBytes: integer('reelMaxBufferBytes', 2097152),
        maxBufferEvents: integer('reelMaxBufferEvents', 10000),
        maxPendingUploads: integer('reelMaxPendingUploads', 8),
        maxRetries: integer('reelMaxRetries', 5),
        circuitFailures: integer('reelCircuitFailures', 5),
        circuitCooldown: integer('reelCircuitCooldown', 60000),
        testing: Boolean(script && script.dataset.reelTesting === 'true'),
    });
    const state = {
        status: 'idle',
        started: false,
        startPromise: null,
        hiddenLatched: false,
        hooksInstalled: false,
        lifecycleInstalled: false,
        incomplete: false,
        reason: null,
        events: [],
        hasFullSnapshot: false,
        pendingMeta: null,
        bufferBytes: 0,
        pending: [],
        sequence: 0,
        epochId: null,
        session: null,
        stopRrweb: null,
        timer: null,
        expiryTimer: null,
        sending: false,
        consecutiveFailures: 0,
        circuitUntil: 0,
        maskedNodeIds: new Set(),
        styleNodeIds: new Set(),
        childNodeIds: new Map(),
        originalFetch: null,
        fetchWrapper: null,
        originalXhrOpen: null,
        originalXhrSend: null,
        xhrOpenWrapper: null,
        xhrSendWrapper: null,
        xhrMetadata: new WeakMap(),
    };

    function stripQueryAndFragment(value) {
        if (typeof value !== 'string' || value === '') return value;

        try {
            const parsed = new URL(value, window.location.origin);
            return parsed.origin === window.location.origin
                ? parsed.pathname
                : parsed.origin + parsed.pathname;
        } catch (_) {
            return value.split(/[?#]/, 1)[0];
        }
    }

    function isSameOrigin(value) {
        try {
            return new URL(String(value), window.location.origin).origin === window.location.origin;
        } catch (_) {
            return false;
        }
    }

    function sanitizeMethod(value) {
        const method = String(value || 'GET').toUpperCase();
        return /^[A-Z0-9!#$%&'*+.^_`|~-]{1,32}$/.test(method) ? method : 'UNKNOWN';
    }

    function sanitizePath(value) {
        const path = stripQueryAndFragment(String(value || ''));
        return typeof path === 'string' && path.startsWith('/') ? path.slice(0, 2048) : '/';
    }

    function requestDetails(input, options) {
        const url = input && input.url ? input.url : String(input);
        const method = (options && options.method) || (input && input.method) || 'GET';
        return { url: url, method: sanitizeMethod(method), sameOrigin: isSameOrigin(url) };
    }

    function applicationFetchArguments(args, details) {
        if (! details.sameOrigin || ! state.session || ! state.session.sessionId) return args;

        try {
            const options = Object.assign({}, args[1] || {});
            const sourceHeaders = options.headers || (args[0] && args[0].headers);
            const headers = new window.Headers(sourceHeaders || {});
            headers.set('X-Reel-Session', state.session.sessionId);
            options.headers = headers;

            const correlated = Array.from(args);
            correlated[1] = options;
            return correlated;
        } catch (_) {
            state.reason = 'fetch_correlation_failed';
            return args;
        }
    }

    function validatedUploadUrl(value) {
        const configured = new URL(String(config.reelUrl || ''));
        const upload = new URL(String(value || ''));

        if (! /^https?:$/.test(configured.protocol)
            || ! /^https?:$/.test(upload.protocol)
            || configured.origin !== upload.origin) {
            throw new Error('upload_origin_mismatch');
        }

        return upload.href;
    }

    function sanitizeCss(value) {
        if (typeof value !== 'string') return '';

        return value
            .replace(/@import\s+[^;{}]+;?/gi, '')
            .replace(/url\s*\(\s*(?:"[^"]*"|'[^']*'|[^)]*)\s*\)/gi, 'none')
            .replace(/expression\s*\([^)]*\)/gi, '');
    }

    function sanitizeCssMutation(value) {
        if (typeof value === 'string') {
            const sanitized = sanitizeCss(value);
            return /url\s*\(/i.test(sanitized) ? null : sanitized;
        }
        if (Array.isArray(value)) {
            const sanitized = [];
            for (const item of value) {
                const clean = sanitizeCssMutation(item);
                if (clean === null) return null;
                sanitized.push(clean);
            }
            return sanitized;
        }
        if (value && typeof value === 'object') {
            const sanitized = {};
            for (const key of Object.keys(value)) {
                const clean = sanitizeCssMutation(value[key]);
                if (clean === null) return null;
                sanitized[key] = clean;
            }
            return sanitized;
        }
        return value;
    }

    function sanitizeInlineStyle(value) {
        return sanitizeCss(value)
            .split(';')
            .map((declaration) => declaration.trim())
            .filter((declaration) => declaration !== '' && !/^behavior\s*:/i.test(declaration))
            .join('; ');
    }

    function cleanAttributes(attributes, masked) {
        const clean = {};

        if (!attributes || typeof attributes !== 'object') return clean;

        Object.keys(attributes).forEach((name) => {
            const lower = name.toLowerCase();
            if (SANITIZER_RULES.hydrationAttributes.includes(lower)
                || SANITIZER_RULES.urlAttributes.includes(lower)
                || lower.startsWith('on')) return;

            if (lower === SANITIZER_RULES.styleAttribute) {
                const style = sanitizeInlineStyle(String(attributes[name]));
                if (style !== '') clean[name] = style;
                return;
            }

            if (lower === '_csstext') {
                const css = sanitizeCss(String(attributes[name]));
                if (css !== '') clean[name] = css;
                return;
            }

            if (lower === 'value' && masked) {
                clean[name] = SANITIZER_RULES.maskText;
                return;
            }

            clean[name] = attributes[name];
        });

        return clean;
    }

    function isDataImage(tagName, attributes) {
        return tagName === 'img'
            && attributes
            && typeof attributes.src === 'string'
            && /^data:image\//i.test(attributes.src);
    }

    function markMasked(nodeId) {
        if (!Number.isInteger(nodeId) || state.maskedNodeIds.has(nodeId)) return;
        state.maskedNodeIds.add(nodeId);
        const children = state.childNodeIds.get(nodeId) || [];
        children.forEach(markMasked);
    }

    function sanitizeNode(node, inheritedMask, parentId) {
        if (!node || typeof node !== 'object') return node;
        if (Number.isInteger(parentId) && Number.isInteger(node.id)) {
            const children = state.childNodeIds.get(parentId) || [];
            if (!children.includes(node.id)) children.push(node.id);
            state.childNodeIds.set(parentId, children);
        }
        if (inheritedMask && Number.isInteger(node.id)) markMasked(node.id);

        if (node.type === 2) {
            const tagName = String(node.tagName || '').toLowerCase();
            const attributes = node.attributes || {};
            const masked = Boolean(inheritedMask)
                || SANITIZER_RULES.maskedTags.includes(tagName)
                || Object.prototype.hasOwnProperty.call(attributes, SANITIZER_RULES.contentEditableAttribute)
                || Object.prototype.hasOwnProperty.call(attributes, 'data-reel-mask');
            const blocked = SANITIZER_RULES.blockedTags.includes(tagName)
                || Object.prototype.hasOwnProperty.call(attributes, 'data-reel-block')
                || isDataImage(tagName, attributes);

            if (Number.isInteger(node.id)) {
                if (masked) markMasked(node.id);
                if (tagName === 'style') state.styleNodeIds.add(node.id);
            }

            if (blocked) {
                const safe = cleanAttributes(attributes, true);
                const placeholder = {};
                ['class', 'width', 'height', 'style'].forEach((name) => {
                    if (Object.prototype.hasOwnProperty.call(safe, name)) placeholder[name] = safe[name];
                });
                placeholder['data-reel-blocked'] = tagName || 'element';
                node.tagName = 'div';
                node.attributes = placeholder;
                node.childNodes = [];
                return node;
            }

            node.attributes = cleanAttributes(attributes, masked);
            if (Array.isArray(node.childNodes)) {
                node.childNodes = node.childNodes.map((child) => sanitizeNode(child, masked, node.id));
            }

            return node;
        }

        if (node.type === 3) {
            if (inheritedMask) node.textContent = SANITIZER_RULES.maskText;
            if (node.isStyle === true) {
                node.textContent = sanitizeCss(String(node.textContent || ''));
                if (Number.isInteger(node.id)) state.styleNodeIds.add(node.id);
            }
        }

        if (Array.isArray(node.childNodes)) {
            node.childNodes = node.childNodes.map((child) => sanitizeNode(child, inheritedMask, node.id));
        }

        return node;
    }

    function sanitizeAttributeMutation(mutation) {
        if (!mutation || typeof mutation !== 'object') return mutation;
        if (mutation.attributes
            && (Object.prototype.hasOwnProperty.call(mutation.attributes, SANITIZER_RULES.contentEditableAttribute)
                || Object.prototype.hasOwnProperty.call(mutation.attributes, 'data-reel-mask'))) {
            markMasked(mutation.id);
        }
        const masked = state.maskedNodeIds.has(mutation.id);
        mutation.attributes = cleanAttributes(mutation.attributes, masked);
        return mutation;
    }

    function sanitizeEvent(rawEvent) {
        let event;
        try {
            event = JSON.parse(JSON.stringify(rawEvent));
        } catch (_) {
            return null;
        }

        if (!event || typeof event !== 'object') return null;

        if (event.type === 4 && event.data && typeof event.data.href === 'string') {
            event.data.href = stripQueryAndFragment(event.data.href);
        }

        if (event.type === 2 && event.data && event.data.node) {
            event.data.node = sanitizeNode(event.data.node, false, null);
        }

        if (event.type === 3 && event.data) {
            if (Array.isArray(event.data.adds)) {
                event.data.adds = event.data.adds.map((addition) => {
                    if (addition && addition.node) {
                        addition.node = sanitizeNode(
                            addition.node,
                            state.maskedNodeIds.has(addition.parentId),
                            addition.parentId,
                        );
                    }
                    return addition;
                });
            }
            if (Array.isArray(event.data.attributes)) {
                event.data.attributes = event.data.attributes.map(sanitizeAttributeMutation);
            }
            if (Array.isArray(event.data.texts)) {
                event.data.texts = event.data.texts.map((text) => {
                    if (state.maskedNodeIds.has(text.id)) text.value = SANITIZER_RULES.maskText;
                    if (state.styleNodeIds.has(text.id)) text.value = sanitizeCss(String(text.value || ''));
                    return text;
                });
            }
            if (event.data.source === 5 && Object.prototype.hasOwnProperty.call(event.data, 'text')) {
                event.data.text = SANITIZER_RULES.maskText;
            }
            if ([8, 13, 15].includes(event.data.source)) {
                const data = sanitizeCssMutation(event.data);
                if (data === null) return null;
                event.data = data;
            }
            if (typeof event.data.href === 'string') event.data.href = stripQueryAndFragment(event.data.href);
        }

        return event;
    }

    function byteLength(value) {
        return new TextEncoder().encode(value).byteLength;
    }

    function randomId() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();
        const bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
    }

    function markIncomplete(reason) {
        state.incomplete = true;
        state.reason = reason;
        stop(reason, true);
    }

    function bufferEvent(rawEvent) {
        if (!state.started) return;
        if (state.session && state.session.maxEventTime * 1000 <= Date.now()) {
            stop('max_event_time', false);
            return;
        }
        const event = sanitizeEvent(rawEvent);
        if (!event) {
            markIncomplete('sanitization_failed');
            return;
        }

        if (!state.hasFullSnapshot) {
            if (event.type === 4) {
                state.pendingMeta = event;
                return;
            }
            if (event.type !== 2) {
                markIncomplete('missing_full_snapshot');
                return;
            }
            state.hasFullSnapshot = true;
        }

        const appended = appendEvent(event);
        if (appended && event.type === 2 && state.pendingMeta) {
            appendEvent(state.pendingMeta);
            state.pendingMeta = null;
        }
        if (state.bufferBytes >= config.flushBytes) void flush(false);
    }

    function appendEvent(event) {
        const encoded = JSON.stringify(event);
        const size = byteLength(encoded);
        if (state.events.length + 1 > config.maxBufferEvents
            || state.bufferBytes + size > config.maxBufferBytes) {
            markIncomplete('buffer_ceiling');
            return false;
        }

        state.events.push(event);
        state.bufferBytes += size;
        return true;
    }

    async function gzip(value) {
        if (typeof window.CompressionStream !== 'function') throw new Error('gzip_unavailable');
        const stream = new Blob([value]).stream().pipeThrough(new CompressionStream('gzip'));
        return new Uint8Array(await new Response(stream).arrayBuffer());
    }

    function base64(bytes) {
        let binary = '';
        for (let offset = 0; offset < bytes.length; offset += 0x8000) {
            binary += String.fromCharCode.apply(null, bytes.subarray(offset, offset + 0x8000));
        }
        return window.btoa(binary);
    }

    async function sha256(bytes) {
        const digest = await window.crypto.subtle.digest('SHA-256', bytes);
        return Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, '0')).join('');
    }

    async function makeEnvelope(events) {
        const compressed = await gzip(JSON.stringify(events));
        const timestamps = events.map((event) => Number(event.timestamp) || Date.now());
        return {
            envelope_version: config.envelopeVersion,
            recorder_version: config.recorderVersion,
            rrweb_version: config.rrwebVersion,
            compression: config.compression,
            application_id: state.session.applicationId,
            session_id: state.session.sessionId,
            epoch_id: state.epochId,
            sequence: state.sequence++,
            checksum: await sha256(compressed),
            event_started_at: Math.min.apply(null, timestamps),
            event_ended_at: Math.max.apply(null, timestamps),
            payload: base64(compressed),
            grant: state.session.grant,
        };
    }

    function scheduleRetry(item) {
        const exponent = Math.min(item.attempts, 6);
        const delay = Math.min(30000, 500 * (2 ** exponent));
        item.nextAttempt = Date.now() + Math.floor(delay / 2 + Math.random() * delay / 2);
    }

    async function transmit(item, unload) {
        const body = JSON.stringify(item.envelope);
        if (unload && navigator.sendBeacon) {
            // sendBeacon has no redirect mode; unload redirects cannot be made fail-closed here.
            return navigator.sendBeacon(state.session.uploadUrl, new Blob([body], { type: 'text/plain' }));
        }

        const fetchFunction = state.originalFetch || window.fetch;
        const response = await Reflect.apply(fetchFunction, window, [state.session.uploadUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'text/plain' },
            body: body,
            keepalive: Boolean(unload),
            credentials: 'omit',
            redirect: 'error',
        }]);
        return response.ok;
    }

    async function drain(unload) {
        if (state.sending || state.pending.length === 0) return;
        if (Date.now() < state.circuitUntil) return;
        state.sending = true;

        try {
            while (state.pending.length > 0) {
                const item = state.pending[0];
                if (!unload && item.nextAttempt > Date.now()) break;

                let sent = false;
                try {
                    sent = await transmit(item, unload);
                } catch (_) {
                    sent = false;
                }

                if (sent) {
                    state.pending.shift();
                    state.consecutiveFailures = 0;
                    continue;
                }

                item.attempts += 1;
                state.consecutiveFailures += 1;
                if (item.attempts > config.maxRetries) {
                    markIncomplete('retry_ceiling');
                    break;
                }
                if (state.consecutiveFailures >= config.circuitFailures) {
                    state.circuitUntil = Date.now() + config.circuitCooldown;
                    markIncomplete('circuit_open');
                    break;
                }
                scheduleRetry(item);
                break;
            }
        } finally {
            state.sending = false;
        }
    }

    async function flush(unload) {
        if (!state.session || state.events.length === 0) {
            await drain(unload);
            return;
        }

        const events = state.events;
        state.events = [];
        state.bufferBytes = 0;

        try {
            const envelope = await makeEnvelope(events);
            if (state.pending.length >= config.maxPendingUploads) {
                markIncomplete('pending_upload_ceiling');
                return;
            }
            state.pending.push({ envelope: envelope, attempts: 0, nextAttempt: 0 });
            await drain(unload);
        } catch (_) {
            markIncomplete('compression_failed');
        }
    }

    function inspectResponse(response, method, url, sameOrigin) {
        try {
            if (sameOrigin !== true && ! isSameOrigin(url)) return;
            const capturePolicy = response && response.headers && response.headers.get('X-Reel-Capture');
            if (capturePolicy === 'hidden') {
                state.hiddenLatched = true;
                state.session = null;
                try { window.sessionStorage.removeItem('artisan-build.reel.session'); } catch (_) {}
                stop('hidden', true);
                return;
            }
            if (capturePolicy === 'allowed' && state.hiddenLatched) {
                state.hiddenLatched = false;
                state.status = 'idle';
                state.reason = null;
            }
            if (response && response.status >= 500) {
                const serverError = response.headers
                    && response.headers.get('X-Reel-Server-Error') === '1';
                bufferEvent({
                    type: 5,
                    timestamp: Date.now(),
                    data: {
                        tag: serverError ? 'reel.server_error' : 'reel.error',
                        payload: {
                            method: sanitizeMethod(method),
                            path: sanitizePath(url),
                            status: response.status,
                        },
                    },
                });
            }
        } catch (_) {
            state.reason = 'response_observation_failed';
        }
    }

    function installHooks() {
        if (state.hooksInstalled) return;
        state.hooksInstalled = true;

        try {
            state.originalFetch = window.fetch;
            if (typeof state.originalFetch === 'function') {
                state.fetchWrapper = function () {
                    const args = arguments;
                    const receiver = this;
                    const details = requestDetails(args[0], args[1]);
                    const result = Reflect.apply(
                        state.originalFetch,
                        receiver,
                        applicationFetchArguments(args, details),
                    );
                    try {
                        Promise.resolve(result).then(
                            (response) => inspectResponse(response, details.method, details.url, details.sameOrigin),
                            () => {},
                        );
                    } catch (_) {
                        state.reason = 'fetch_observation_failed';
                    }
                    return result;
                };
                window.fetch = state.fetchWrapper;
            }
        } catch (_) {
            state.reason = 'fetch_hook_failed';
        }

        try {
            const prototype = window.XMLHttpRequest && window.XMLHttpRequest.prototype;
            if (prototype) {
                state.originalXhrOpen = prototype.open;
                state.originalXhrSend = prototype.send;
                state.xhrOpenWrapper = function (method, url) {
                    try {
                        state.xhrMetadata.set(this, {
                            method: sanitizeMethod(method),
                            url: String(url),
                            sameOrigin: isSameOrigin(url),
                        });
                    } catch (_) {}
                    return Reflect.apply(state.originalXhrOpen, this, arguments);
                };
                state.xhrSendWrapper = function () {
                    const xhr = this;
                    try {
                        const metadata = state.xhrMetadata.get(xhr);
                        if (metadata && metadata.sameOrigin && state.session && state.session.sessionId) {
                            xhr.setRequestHeader('X-Reel-Session', state.session.sessionId);
                        }
                        xhr.addEventListener('loadend', () => {
                            const observed = state.xhrMetadata.get(xhr) || { method: 'GET', url: '', sameOrigin: false };
                            inspectResponse(
                                { status: xhr.status, headers: { get: (name) => xhr.getResponseHeader(name) } },
                                observed.method,
                                observed.url,
                                observed.sameOrigin,
                            );
                        }, { once: true });
                    } catch (_) {}
                    return Reflect.apply(state.originalXhrSend, this, arguments);
                };
                prototype.open = state.xhrOpenWrapper;
                prototype.send = state.xhrSendWrapper;
            }
        } catch (_) {
            state.reason = 'xhr_hook_failed';
        }
    }

    function restoreHooks() {
        try {
            if (window.fetch === state.fetchWrapper) window.fetch = state.originalFetch;
        } catch (_) {}
        try {
            const prototype = window.XMLHttpRequest && window.XMLHttpRequest.prototype;
            if (prototype && prototype.open === state.xhrOpenWrapper) prototype.open = state.originalXhrOpen;
            if (prototype && prototype.send === state.xhrSendWrapper) prototype.send = state.originalXhrSend;
        } catch (_) {}
        state.hooksInstalled = false;
    }

    function installLifecycle() {
        if (state.lifecycleInstalled) return;
        state.lifecycleInstalled = true;
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'hidden') void flush(true);
        });
        window.addEventListener('pagehide', () => void flush(true));
        window.addEventListener('pageshow', (event) => {
            if (event.persisted && state.started) installHooks();
        });
    }

    function storedSession() {
        try {
            const value = JSON.parse(window.sessionStorage.getItem('artisan-build.reel.session'));
            if (value && value.maxEventTime * 1000 > Date.now() + 5000) return value;
        } catch (_) {}
        return null;
    }

    async function acquireSession() {
        const stored = storedSession();
        if (stored) return stored;

        const fetchFunction = state.originalFetch || window.fetch;
        const response = await Reflect.apply(fetchFunction, window, [config.grantUrl, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': config.csrfToken,
            },
            credentials: 'same-origin',
            body: JSON.stringify({ consent: true, path: window.location.pathname || '/' }),
        }]);
        if (!response.ok) throw new Error('grant_rejected');
        const payload = await response.json();
        const session = {
            grant: payload.grant,
            sessionId: payload.session_id,
            applicationId: payload.application_id,
            uploadUrl: validatedUploadUrl(payload.upload_url),
            maxEventTime: payload.max_event_time,
        };
        window.sessionStorage.setItem('artisan-build.reel.session', JSON.stringify(session));
        return session;
    }

    function start(options) {
        if (state.started) return Promise.resolve(status());
        if (state.startPromise) return state.startPromise;
        const settings = options || {};
        if (settings.consent !== true) {
            state.status = 'awaiting_consent';
            return Promise.resolve(status());
        }
        if (settings.refuseOnGpc === true && navigator.globalPrivacyControl === true) {
            state.status = 'refused_gpc';
            return Promise.resolve(status());
        }
        if (state.hiddenLatched) {
            state.status = 'hidden';
            return Promise.resolve(status());
        }

        state.startPromise = beginStart().finally(() => {
            state.startPromise = null;
        });

        return state.startPromise;
    }

    async function beginStart() {
        state.status = 'starting';
        try {
            installHooks();
            installLifecycle();
            state.session = await acquireSession();
            if (state.hiddenLatched) throw new Error('hidden');
            state.epochId = randomId();
            state.sequence = 0;
            state.incomplete = false;
            state.reason = null;
            state.hasFullSnapshot = false;
            state.pendingMeta = null;
            state.maskedNodeIds.clear();
            state.styleNodeIds.clear();
            state.childNodeIds.clear();
            if (!window.rrweb || typeof window.rrweb.record !== 'function') throw new Error('rrweb_unavailable');
            state.started = true;
            state.status = 'recording';
            state.stopRrweb = window.rrweb.record({
                emit: bufferEvent,
                maskAllInputs: true,
                maskTextSelector: '[contenteditable], [data-reel-mask]',
                blockSelector: '[data-reel-block], canvas, video, audio, iframe, object, embed',
                recordCanvas: false,
                collectFonts: false,
                inlineImages: false,
            });
            state.timer = window.setInterval(() => void flush(false), config.batchInterval);
            const expiresIn = state.session.maxEventTime * 1000 - Date.now();
            if (expiresIn <= 0) {
                stop('max_event_time', false);
            } else {
                state.expiryTimer = window.setTimeout(() => stop('max_event_time', false), expiresIn);
            }
        } catch (error) {
            if (error && error.message === 'hidden') {
                stop('hidden', true);
            } else {
                markIncomplete('start_failed');
            }
        }
        return status();
    }

    function stop(reason, discard) {
        if (state.stopRrweb) {
            try { state.stopRrweb(); } catch (_) {}
            state.stopRrweb = null;
        }
        if (state.timer) window.clearInterval(state.timer);
        state.timer = null;
        if (state.expiryTimer) window.clearTimeout(state.expiryTimer);
        state.expiryTimer = null;
        state.started = false;
        state.status = reason === 'hidden' ? 'hidden' : 'stopped';
        state.reason = reason || state.reason;
        if (discard) {
            state.events = [];
            state.bufferBytes = 0;
        } else {
            void flush(false);
        }
        restoreHooks();
        return status();
    }

    function status() {
        return Object.freeze({
            state: state.status,
            incomplete: state.incomplete,
            reason: state.reason,
            globalPrivacyControl: navigator.globalPrivacyControl === true,
            bufferedEvents: state.events.length,
            pendingUploads: state.pending.length,
        });
    }

    const testing = config.testing ? {
        __testing: Object.freeze({
            flush: flush,
            inspectResponse: inspectResponse,
            sanitizeEvent: sanitizeEvent,
            state: () => state,
        }),
    } : {};
    const api = Object.freeze({ start: start, stop: () => stop('host_stop', false), status: status, ...testing });
    state.api = api;
    window[singletonKey] = state;
    window.Reel = api;
})(window, document);
