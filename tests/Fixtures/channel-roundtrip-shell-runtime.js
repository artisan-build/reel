globalThis.roundTripNonce = 'a'.repeat(96);
globalThis.roundTripShellWindow = {
    ReelMessageChannel: globalThis.ReelMessageChannel,
    addEventListener(name, listener) {
        if (name === 'message') this.messageListener = listener;
    },
    removeEventListener() {},
    setTimeout() { return 1; },
    clearTimeout() {},
};
globalThis.window = roundTripShellWindow;
