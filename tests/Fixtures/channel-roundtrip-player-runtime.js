globalThis.playerToShellMessages = [];
globalThis.shellToPlayerMessages = [];
globalThis.roundTripPlayerCalls = [];
globalThis.roundTripPlayerWindow = {
    parent: roundTripShellWindow,
    ReelMessageChannel: globalThis.ReelMessageChannel,
    addEventListener(name, listener) {
        if (name === 'message') this.messageListener = listener;
    },
    setInterval() { return 1; },
    clearInterval() {},
    rrweb: {
        Replayer: class {
            play(value) { roundTripPlayerCalls.push(['play', value]); }
            pause() { roundTripPlayerCalls.push(['pause']); }
            goto(value, playing) { roundTripPlayerCalls.push(['goto', value, playing]); }
            setConfig(value) { roundTripPlayerCalls.push(['config', value]); }
            getCurrentTime() { return 0; }
        },
    },
};
roundTripShellWindow.postMessage = (message, targetOrigin) => {
    playerToShellMessages.push({ message: message, targetOrigin: targetOrigin });
    roundTripShell.listener({ source: roundTripPlayerWindow, origin: 'null', data: message });
};
roundTripPlayerWindow.postMessage = (message, targetOrigin) => {
    shellToPlayerMessages.push({ message: message, targetOrigin: targetOrigin });
    roundTripPlayerWindow.messageListener({
        source: roundTripShellWindow,
        origin: 'https://reel.example',
        data: message,
    });
};
globalThis.roundTripShell = roundTripShellWindow.ReelReplayShell({
    playerUrlEndpoint: 'https://reel.example/player-url',
    nonce: roundTripNonce,
});
roundTripShell.$refs = { frame: { contentWindow: roundTripPlayerWindow } };
roundTripShell.ready = true;
roundTripShell.init();
globalThis.window = roundTripPlayerWindow;
globalThis.roundTripElements = {
    'reel-configuration': { textContent: JSON.stringify({
        channelNonce: roundTripNonce,
        parentOrigin: 'https://reel.example',
        start: 0,
        diagnostic: null,
    }) },
    'reel-events': { textContent: JSON.stringify([
        { type: 2, timestamp: 1000, data: {} },
        { type: 3, timestamp: 1100, data: {} },
    ]) },
    'reel-player': {},
    'reel-diagnostic': { hidden: true, textContent: '' },
};
globalThis.document = { getElementById: (id) => roundTripElements[id] };
