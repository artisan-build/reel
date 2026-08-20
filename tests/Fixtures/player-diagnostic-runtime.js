globalThis.window = globalThis;
globalThis.diagnosticMessages = [];
window.parent = {
    postMessage(message, origin) { diagnosticMessages.push({ message: message, origin: origin }); },
};
globalThis.diagnosticElement = { hidden: true, textContent: '' };
const diagnosticElements = {
    'reel-configuration': { textContent: JSON.stringify({
        channelNonce: 'a'.repeat(96),
        parentOrigin: 'https://reel.example',
        start: 0,
        diagnostic: 'replay_not_ready',
    }) },
    'reel-events': { textContent: '[]' },
    'reel-player': {},
    'reel-diagnostic': diagnosticElement,
};
globalThis.document = { getElementById: (id) => diagnosticElements[id] };
