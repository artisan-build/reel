globalThis.window = globalThis;
globalThis.navigator = { globalPrivacyControl: false, sendBeacon: function () { return true; } };
globalThis.document = {
    currentScript: {
        dataset: {
            reelTesting: 'true',
            reelGrantUrl: '/grant',
            reelCsrfToken: 'csrf',
            reelEnvelopeVersion: '1',
            reelRecorderVersion: '0.1.0',
            reelRrwebVersion: '2.1.1',
            reelCompression: 'gzip',
            reelBatchInterval: '10000',
            reelFlushBytes: '1000000',
            reelMaxBufferBytes: '2000000',
            reelMaxBufferEvents: '10000',
            reelMaxPendingUploads: '8',
            reelMaxRetries: '5',
            reelCircuitFailures: '5',
            reelCircuitCooldown: '60000'
        }
    },
    visibilityState: 'visible',
    addEventListener: function () {}
};
globalThis.location = { origin: 'https://host.example', pathname: '/start' };
globalThis.addEventListener = function () {};

globalThis.Headers = class {
    constructor(values) {
        this.values = {};
        if (values instanceof globalThis.Headers) {
            Object.assign(this.values, values.values);
        } else if (values && typeof values === 'object') {
            Object.keys(values).forEach((name) => { this.values[name.toLowerCase()] = String(values[name]); });
        }
    }
    set(name, value) { this.values[String(name).toLowerCase()] = String(value); }
    get(name) { return this.values[String(name).toLowerCase()] || null; }
};

const reelStorage = new Map();
globalThis.sessionStorage = {
    getItem: function (key) { return reelStorage.has(key) ? reelStorage.get(key) : null; },
    setItem: function (key, value) { reelStorage.set(key, String(value)); },
    removeItem: function (key) { reelStorage.delete(key); }
};

let reelNow = 1000000;
Date.now = function () { return reelNow; };

const reelIntervals = [];
const reelTimeouts = [];
globalThis.setInterval = function (callback, delay) {
    const timer = { callback: callback, delay: delay, active: true };
    reelIntervals.push(timer);
    return timer;
};
globalThis.clearInterval = function (timer) { if (timer) timer.active = false; };
globalThis.setTimeout = function (callback, delay) {
    const timer = { callback: callback, delay: delay, active: true };
    reelTimeouts.push(timer);
    return timer;
};
globalThis.clearTimeout = function (timer) { if (timer) timer.active = false; };

globalThis.URL = class {
    constructor(value, base) {
        const absolute = /^(https?:\/\/[^/]+)([^?#]*)/.exec(String(value));
        this.origin = absolute ? absolute[1] : String(base).replace(/\/$/, '');
        this.pathname = absolute ? (absolute[2] || '/') : (String(value).split(/[?#]/)[0] || '/');
    }
};

globalThis.TextEncoder = class {
    encode(value) {
        const bytes = new Uint8Array(String(value).length);
        for (let index = 0; index < bytes.length; index += 1) bytes[index] = String(value).charCodeAt(index) & 255;
        return bytes;
    }
};

globalThis.Blob = class {
    constructor(parts) { this.value = parts.map(String).join(''); }
    stream() {
        return {
            value: this.value,
            pipeThrough: function () { return this; }
        };
    }
};
globalThis.CompressionStream = class { constructor(format) { this.format = format; } };
globalThis.Response = class {
    constructor(stream) { this.stream = stream; }
    arrayBuffer() { return Promise.resolve(new TextEncoder().encode(this.stream.value).buffer); }
};

const base64Alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
globalThis.btoa = function (binary) {
    let output = '';
    for (let offset = 0; offset < binary.length; offset += 3) {
        const first = binary.charCodeAt(offset) & 255;
        const second = offset + 1 < binary.length ? binary.charCodeAt(offset + 1) & 255 : 0;
        const third = offset + 2 < binary.length ? binary.charCodeAt(offset + 2) & 255 : 0;
        const combined = (first << 16) | (second << 8) | third;
        output += base64Alphabet[(combined >> 18) & 63];
        output += base64Alphabet[(combined >> 12) & 63];
        output += offset + 1 < binary.length ? base64Alphabet[(combined >> 6) & 63] : '=';
        output += offset + 2 < binary.length ? base64Alphabet[combined & 63] : '=';
    }
    return output;
};

let reelUuid = 0;
globalThis.crypto = {
    randomUUID: function () { reelUuid += 1; return 'epoch-' + reelUuid; },
    getRandomValues: function (bytes) { bytes.fill(7); return bytes; },
    subtle: {
        digest: function () { return Promise.resolve(new Uint8Array(32).buffer); }
    }
};

const reelUploads = [];
let reelGrantRequests = 0;
const reelBody = 'byte-identical-response-body';
function reelResponse(status, responseHeaders) {
    return {
        ok: status >= 200 && status < 300,
        status: status,
        bodyUsed: false,
        headers: {
            get: function (name) { return (responseHeaders || {})[String(name).toLowerCase()] || null; }
        },
        text: function () { this.bodyUsed = true; return Promise.resolve(reelBody); }
    };
}
const reelHostFetchResults = [];
const reelRejectedError = new Error('host-fetch-rejected');
const reelRejectedFetchResult = Promise.reject(reelRejectedError);
reelRejectedFetchResult.catch(function () {});
const reelHostFetchCalls = [];
function reelOriginalFetch(url, options) {
    if (url === '/grant') {
        reelGrantRequests += 1;
        return Promise.resolve({
            ok: true,
            status: 200,
            headers: { get: function () { return null; } },
            json: function () {
                return Promise.resolve({
                    grant: 'grant-' + reelGrantRequests,
                    session_id: 'session-' + reelGrantRequests,
                    application_id: 'application-1',
                    upload_url: '/upload',
                    max_event_time: Math.floor(Date.now() / 1000) + 60
                });
            }
        });
    }
    if (url === '/host-request') {
        reelHostFetchCalls.push({ receiver: this, url: url, options: options });
        const result = Promise.resolve(reelResponse(204));
        reelHostFetchResults.push(result);
        return result;
    }
    if (url === '/host-error') {
        reelHostFetchCalls.push({ receiver: this, url: url, options: options });
        return Promise.resolve(reelResponse(503));
    }
    if (url === '/host-server-error') {
        reelHostFetchCalls.push({ receiver: this, url: url, options: options });
        return Promise.resolve(reelResponse(500, { 'x-reel-server-error': '1' }));
    }
    if (url === 'https://other.example/error') {
        reelHostFetchCalls.push({ receiver: this, url: url, options: options });
        return Promise.resolve(reelResponse(500));
    }
    if (url === '/host-reject') return reelRejectedFetchResult;
    if (url === '/upload') reelUploads.push(JSON.parse(options.body));
    return Promise.resolve({ ok: true, status: 202, headers: { get: function () { return null; } } });
}
globalThis.fetch = reelOriginalFetch;

const reelXhrOpenResult = { operation: 'open-result' };
const reelXhrSendResult = { operation: 'send-result' };
const reelXhrOpenCalls = [];
const reelXhrSendCalls = [];
globalThis.XMLHttpRequest = function () {
    this._listeners = {};
    this._headers = {};
    this.status = 200;
    this.responseText = 'byte-identical-xhr-body';
};
globalThis.XMLHttpRequest.prototype = {
    open: function (method, url) {
        this._url = String(url);
        reelXhrOpenCalls.push({ receiver: this, arguments: Array.from(arguments) });
        return reelXhrOpenResult;
    },
    send: function () {
        reelXhrSendCalls.push({ receiver: this, arguments: Array.from(arguments) });
        if (this._url === '/host-xhr-error') this.status = 502;
        if (this._url === '/host-xhr-server-error') {
            this.status = 500;
            this._responseHeaders = { 'x-reel-server-error': '1' };
        }
        if (this._url === 'https://other.example/xhr-error') this.status = 500;
        if (this._listeners.loadend) this._listeners.loadend();
        return reelXhrSendResult;
    },
    setRequestHeader: function (name, value) { this._headers[String(name).toLowerCase()] = String(value); },
    addEventListener: function (name, callback) { this._listeners[name] = callback; },
    getResponseHeader: function (name) {
        return (this._responseHeaders || {})[String(name).toLowerCase()] || null;
    }
};

let reelRecordCalls = 0;
let reelEmit = null;
globalThis.rrweb = {
    record: function (options) {
        reelRecordCalls += 1;
        reelEmit = options.emit;
        options.emit({ type: 4, timestamp: Date.now(), data: { href: 'https://host.example/start?secret=meta#fragment' } });
        options.emit({
            type: 2,
            timestamp: Date.now() + 1,
            data: { node: { type: 0, id: 1, childNodes: [{ type: 2, id: 2, tagName: 'div', attributes: {}, childNodes: [] }] } }
        });
        options.emit({ type: 3, timestamp: Date.now() + 2, data: { source: 0, adds: [], attributes: [], texts: [] } });
        return function () {};
    }
};

globalThis.reelHarness = {
    intervals: reelIntervals,
    timeouts: reelTimeouts,
    storage: reelStorage,
    uploads: reelUploads,
    emit: function (event) { reelEmit(event); },
    grantRequests: function () { return reelGrantRequests; },
    recordCalls: function () { return reelRecordCalls; },
    hostFetchResults: reelHostFetchResults,
    rejectedFetchResult: reelRejectedFetchResult,
    rejectedError: reelRejectedError,
    originalFetch: reelOriginalFetch,
    responseBody: reelBody,
    hostFetchCalls: reelHostFetchCalls,
    xhrOpenResult: reelXhrOpenResult,
    xhrSendResult: reelXhrSendResult,
    xhrOpenCalls: reelXhrOpenCalls,
    xhrSendCalls: reelXhrSendCalls
};
