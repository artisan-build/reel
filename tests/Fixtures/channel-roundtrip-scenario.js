const readyMessage = playerToShellMessages[0].message;
const middle = Math.floor(roundTripNonce.length / 2);
const nearMissNonce = roundTripNonce.slice(0, middle) + 'b' + roundTripNonce.slice(middle + 1);
const playerCallCount = () => roundTripPlayerCalls.length;

roundTripShell.command('play');
const commandRoundTrip = shellToPlayerMessages[0];

roundTripShell.ready = false;
roundTripShell.listener({
    source: roundTripPlayerWindow,
    origin: 'null',
    data: Object.assign({}, readyMessage, { nonce: nearMissNonce }),
});
const nearMissRejected = roundTripShell.ready === false;
roundTripShell.listener({ source: {}, origin: 'null', data: readyMessage });
const wrongWindowRejected = roundTripShell.ready === false;
roundTripShell.listener({ source: roundTripPlayerWindow, origin: 'https://reel.example', data: readyMessage });
const wrongOpaqueOriginRejected = roundTripShell.ready === false;
roundTripShell.listener({ source: roundTripPlayerWindow, origin: 'null', data: Object.assign({}, readyMessage, { type: 'unknown' }) });
const unknownTypeRejected = roundTripShell.ready === false;
roundTripShell.listener({ source: roundTripPlayerWindow, origin: 'null', data: { source: 'reel-player' } });
const malformedRejected = roundTripShell.ready === false;
roundTripShell.listener({ source: roundTripPlayerWindow, origin: 'null', data: Object.assign({}, readyMessage, { extra: true }) });
const extraKeyRejected = roundTripShell.ready === false;

const beforeWrongPlayerOrigin = playerCallCount();
roundTripPlayerWindow.messageListener({
    source: roundTripShellWindow,
    origin: 'null',
    data: commandRoundTrip.message,
});
const nullParentOriginRejected = playerCallCount() === beforeWrongPlayerOrigin;
roundTripPlayerWindow.messageListener({
    source: {},
    origin: 'https://reel.example',
    data: commandRoundTrip.message,
});
const wrongParentWindowRejected = playerCallCount() === beforeWrongPlayerOrigin;

print(JSON.stringify({
    playerSender: readyMessage.source === 'reel-player' && readyMessage.nonce === roundTripNonce,
    shellAcceptedRealReady: playerToShellMessages[0].targetOrigin === 'https://reel.example',
    shellSender: commandRoundTrip.message.source === 'reel-shell' && commandRoundTrip.message.nonce === roundTripNonce,
    playerAcceptedRealCommand: roundTripPlayerCalls.some((call) => call[0] === 'play'),
    nearMissRejected: nearMissRejected,
    wrongWindowRejected: wrongWindowRejected,
    wrongOpaqueOriginRejected: wrongOpaqueOriginRejected,
    unknownTypeRejected: unknownTypeRejected,
    malformedRejected: malformedRejected,
    extraKeyRejected: extraKeyRejected,
    nullParentOriginRejected: nullParentOriginRejected,
    wrongParentWindowRejected: wrongParentWindowRejected,
}));
