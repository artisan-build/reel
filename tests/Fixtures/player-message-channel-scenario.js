const playerWindow = {};
const parentWindow = {};
const nonce = 'a'.repeat(96);
const ready = {
    source: 'reel-player',
    type: 'ready',
    nonce: nonce,
    duration: 100,
};
const command = {
    source: 'reel-shell',
    type: 'command',
    nonce: nonce,
    command: 'seek',
    value: 42,
};

print(JSON.stringify({
    playerAccepted: ReelMessageChannel.acceptPlayerMessage({ source: playerWindow, origin: 'null', data: ready }, playerWindow, nonce) !== null,
    wrongPlayerOrigin: ReelMessageChannel.acceptPlayerMessage({ source: playerWindow, origin: 'https://reel.example', data: ready }, playerWindow, nonce) === null,
    wrongPlayerWindow: ReelMessageChannel.acceptPlayerMessage({ source: {}, origin: 'null', data: ready }, playerWindow, nonce) === null,
    wrongPlayerNonce: ReelMessageChannel.acceptPlayerMessage({ source: playerWindow, origin: 'null', data: Object.assign({}, ready, { nonce: 'wrong' }) }, playerWindow, nonce) === null,
    unknownPlayerType: ReelMessageChannel.acceptPlayerMessage({ source: playerWindow, origin: 'null', data: Object.assign({}, ready, { type: 'unknown' }) }, playerWindow, nonce) === null,
    malformedPlayerBody: ReelMessageChannel.acceptPlayerMessage({ source: playerWindow, origin: 'null', data: Object.assign({}, ready, { extra: true }) }, playerWindow, nonce) === null,
    shellAccepted: ReelMessageChannel.acceptShellMessage({ source: parentWindow, origin: 'https://reel.example', data: command }, parentWindow, 'https://reel.example', nonce) !== null,
    wrongShellOrigin: ReelMessageChannel.acceptShellMessage({ source: parentWindow, origin: 'https://attacker.example', data: command }, parentWindow, 'https://reel.example', nonce) === null,
    wrongShellNonce: ReelMessageChannel.acceptShellMessage({ source: parentWindow, origin: 'https://reel.example', data: Object.assign({}, command, { nonce: 'wrong' }) }, parentWindow, 'https://reel.example', nonce) === null,
    unknownCommand: ReelMessageChannel.acceptShellMessage({ source: parentWindow, origin: 'https://reel.example', data: Object.assign({}, command, { command: 'navigate' }) }, parentWindow, 'https://reel.example', nonce) === null,
    malformedCommand: ReelMessageChannel.acceptShellMessage({ source: parentWindow, origin: 'https://reel.example', data: Object.assign({}, command, { value: '42' }) }, parentWindow, 'https://reel.example', nonce) === null,
}));
