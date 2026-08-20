(function (window, document) {
    'use strict';

    const configuration = JSON.parse(document.getElementById('reel-configuration').textContent);
    const diagnostic = document.getElementById('reel-diagnostic');
    const send = (message) => window.parent.postMessage(Object.assign({
        source: 'reel-player',
        nonce: configuration.channelNonce,
    }, message), configuration.parentOrigin);

    if (configuration.diagnostic) {
        diagnostic.hidden = false;
        diagnostic.textContent = 'This replay cannot be displayed (' + configuration.diagnostic.replaceAll('_', ' ') + ').';
        send({ type: 'diagnostic', code: configuration.diagnostic });
        return;
    }

    let events;
    try {
        events = JSON.parse(document.getElementById('reel-events').textContent);
    } catch (_) {
        diagnostic.hidden = false;
        diagnostic.textContent = 'This replay cannot be displayed (invalid payload).';
        send({ type: 'diagnostic', code: 'invalid_payload' });
        return;
    }

    if (!Array.isArray(events) || events.length === 0 || !window.rrweb || typeof window.rrweb.Replayer !== 'function') {
        diagnostic.hidden = false;
        diagnostic.textContent = 'This replay cannot be displayed (player unavailable).';
        send({ type: 'diagnostic', code: 'player_unavailable' });
        return;
    }

    const firstTimestamp = Math.min.apply(null, events.map((event) => Number(event.timestamp)));
    const lastTimestamp = Math.max.apply(null, events.map((event) => Number(event.timestamp)));
    const duration = Math.max(0, lastTimestamp - firstTimestamp);
    const replayer = new window.rrweb.Replayer(events, {
        root: document.getElementById('reel-player'),
        showWarning: false,
        skipInactive: false,
        UNSAFE_replayCanvas: false,
    });
    let status = 'paused';
    const requestedStart = Number(configuration.start) || 0;
    let position = Math.min(
        duration,
        Math.max(0, requestedStart >= firstTimestamp ? requestedStart - firstTimestamp : requestedStart),
    );

    if (position > 0) replayer.goto(position, false);

    window.addEventListener('message', (event) => {
        const message = window.ReelMessageChannel.acceptShellMessage(
            event,
            window.parent,
            configuration.parentOrigin,
            configuration.channelNonce,
        );
        if (!message) return;

        if (message.command === 'play') {
            replayer.play(position);
            status = 'playing';
        } else if (message.command === 'pause') {
            replayer.pause();
            status = 'paused';
        } else if (message.command === 'seek' || message.command === 'marker') {
            position = Math.min(duration, message.value);
            replayer.goto(position, status === 'playing');
        } else if (message.command === 'speed') {
            replayer.setConfig({ speed: message.value });
        } else if (message.command === 'skip-inactive') {
            replayer.setConfig({ skipInactive: message.value });
        }

        send({ type: 'state', status: status, time: position, duration: duration });
    });

    send({ type: 'ready', duration: duration });
}(window, document));
