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
globalThis.location = { origin: 'https://host.example' };
globalThis.addEventListener = function () {};

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
globalThis.fetch = function (url, options) {
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
    if (url === '/upload') reelUploads.push(JSON.parse(options.body));
    return Promise.resolve({ ok: true, status: 202, headers: { get: function () { return null; } } });
};

globalThis.XMLHttpRequest = function () {};
globalThis.XMLHttpRequest.prototype = {
    open: function () {},
    send: function () {},
    addEventListener: function () {},
    getResponseHeader: function () { return null; }
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
    recordCalls: function () { return reelRecordCalls; }
};
