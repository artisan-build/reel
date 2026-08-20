globalThis.window = globalThis;
globalThis.shellListener = null;
globalThis.shellTimer = null;
globalThis.rejectShellFetch = false;
window.addEventListener = (name, listener) => { if (name === 'message') shellListener = listener; };
window.removeEventListener = () => {};
window.setTimeout = (listener) => { shellTimer = listener; return 1; };
window.clearTimeout = () => { shellTimer = null; };
window.fetch = () => rejectShellFetch
    ? Promise.reject(new Error('network failure'))
    : Promise.resolve({
        ok: true,
        json: () => Promise.resolve({ url: 'https://reel.example/signed-player' }),
    });
