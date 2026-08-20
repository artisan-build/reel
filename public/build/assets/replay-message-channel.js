(function (global) {
    'use strict';

    const exactKeys = (value, keys) => {
        if (!value || typeof value !== 'object' || Array.isArray(value)) return false;
        const actual = Object.keys(value).sort();
        const expected = keys.slice().sort();
        return actual.length === expected.length && actual.every((key, index) => key === expected[index]);
    };

    const validNumber = (value) => typeof value === 'number' && Number.isFinite(value) && value >= 0;
    const validNonce = (value, expected) => {
        if (typeof value !== 'string' || typeof expected !== 'string' || value.length !== expected.length) return false;
        let mismatch = 0;
        for (let index = 0; index < expected.length; index += 1) {
            mismatch |= value.charCodeAt(index) ^ expected.charCodeAt(index);
        }
        return mismatch === 0;
    };

    function acceptPlayerMessage(event, expectedWindow, nonce) {
        if (!event || event.source !== expectedWindow || event.origin !== 'null') return null;
        const data = event.data;
        if (!data || data.source !== 'reel-player' || !validNonce(data.nonce, nonce)) return null;

        if (data.type === 'ready'
            && exactKeys(data, ['source', 'type', 'nonce', 'duration'])
            && validNumber(data.duration)) return data;
        if (data.type === 'state'
            && exactKeys(data, ['source', 'type', 'nonce', 'status', 'time', 'duration'])
            && ['playing', 'paused'].includes(data.status)
            && validNumber(data.time)
            && validNumber(data.duration)) return data;
        if (data.type === 'diagnostic'
            && exactKeys(data, ['source', 'type', 'nonce', 'code'])
            && typeof data.code === 'string'
            && /^[a-z0-9_]{1,64}$/.test(data.code)) return data;

        return null;
    }

    function acceptShellMessage(event, expectedWindow, expectedOrigin, nonce) {
        if (!event
            || typeof expectedOrigin !== 'string'
            || expectedOrigin === '*'
            || expectedOrigin === 'null'
            || event.source !== expectedWindow
            || event.origin !== expectedOrigin) return null;
        const data = event.data;
        if (!data || data.source !== 'reel-shell' || data.type !== 'command' || !validNonce(data.nonce, nonce)) return null;
        if (!['play', 'pause', 'seek', 'speed', 'skip-inactive', 'marker'].includes(data.command)) return null;

        if (['play', 'pause'].includes(data.command)) {
            return exactKeys(data, ['source', 'type', 'nonce', 'command']) ? data : null;
        }
        if (data.command === 'skip-inactive') {
            return exactKeys(data, ['source', 'type', 'nonce', 'command', 'value']) && typeof data.value === 'boolean'
                ? data
                : null;
        }
        if (!exactKeys(data, ['source', 'type', 'nonce', 'command', 'value']) || !validNumber(data.value)) return null;
        if (data.command === 'speed' && ![0.5, 1, 2, 4].includes(data.value)) return null;

        return data;
    }

    function command(nonce, name, value) {
        const message = { source: 'reel-shell', type: 'command', nonce: nonce, command: name };
        if (arguments.length === 3) message.value = value;
        return message;
    }

    global.ReelMessageChannel = Object.freeze({ acceptPlayerMessage, acceptShellMessage, command });
}(typeof globalThis === 'object' ? globalThis : this));
