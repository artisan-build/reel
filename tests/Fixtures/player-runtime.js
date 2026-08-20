globalThis.window = globalThis;
globalThis.parentWindow = {};
window.parent = parentWindow;
globalThis.playerCalls = [];
globalThis.playerMessages = [];
globalThis.playerListeners = {};
globalThis.playerCurrentTime = 10;
globalThis.playerInterval = null;
window.addEventListener = (name, listener) => { playerListeners[name] = listener; };
window.setInterval = (listener) => { playerInterval = listener; return 1; };
window.clearInterval = () => { playerInterval = null; };
window.parent.postMessage = (message, origin) => { playerMessages.push({ message: message, origin: origin }); };
window.rrweb = {
    Replayer: class {
        constructor(events, options) {
            playerCalls.push(['construct', events.length, options.UNSAFE_replayCanvas, options.skipInactive]);
        }
        play(value) { playerCalls.push(['play', value]); }
        pause() { playerCalls.push(['pause']); }
        goto(value, playing) { playerCalls.push(['goto', value, playing]); }
        setConfig(value) { playerCalls.push(['config', value]); }
        getCurrentTime() { return playerCurrentTime; }
    },
};
const elements = {
    'reel-configuration': { textContent: JSON.stringify({
        channelNonce: 'a'.repeat(96),
        parentOrigin: 'https://reel.example',
        start: 10,
        diagnostic: null,
    }) },
    'reel-events': { textContent: JSON.stringify([
        { type: 2, timestamp: 1000, data: {} },
        { type: 3, timestamp: 1100, data: {} },
    ]) },
    'reel-player': {},
    'reel-diagnostic': { hidden: true, textContent: '' },
};
globalThis.document = { getElementById: (id) => elements[id] };
