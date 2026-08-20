const makeShell = () => {
    const frameWindow = {};
    const shell = ReelReplayShell({
        playerUrlEndpoint: 'https://reel.example/player-url',
        nonce: 'a'.repeat(96),
    });
    shell.$refs = { frame: { contentWindow: frameWindow } };
    shell.$nextTick = (callback) => callback();
    shell.init();
    return { shell: shell, frameWindow: frameWindow };
};

(async function () {
    const ready = makeShell();
    await ready.shell.load();
    ready.shell.listener({
        source: ready.frameWindow,
        origin: 'null',
        data: { source: 'reel-player', type: 'ready', nonce: 'a'.repeat(96), duration: 100 },
    });
    const readyResult = {
        ready: ready.shell.ready,
        timerCleared: shellTimer === null,
    };

    const timedOut = makeShell();
    await timedOut.shell.load();
    shellTimer();
    const timeoutResult = timedOut.shell.diagnostic;

    rejectShellFetch = true;
    const unavailable = makeShell();
    await unavailable.shell.load();

    print(JSON.stringify({
        ready: readyResult,
        timeout: timeoutResult,
        unavailable: unavailable.shell.diagnostic,
    }));
}());
