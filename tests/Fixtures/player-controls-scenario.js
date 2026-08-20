const sendCommand = (command, value) => {
    const data = {
        source: 'reel-shell',
        type: 'command',
        nonce: 'a'.repeat(96),
        command: command,
    };
    if (value !== undefined) data.value = value;
    playerListeners.message({ source: parentWindow, origin: 'https://reel.example', data: data });
};

sendCommand('play');
playerCurrentTime = 40;
if (playerInterval) playerInterval();
sendCommand('pause');
sendCommand('play');
sendCommand('seek', 50);
sendCommand('speed', 2);
sendCommand('skip-inactive', true);
sendCommand('marker', 75);
playerListeners.message({
    source: parentWindow,
    origin: 'https://attacker.example',
    data: { source: 'reel-shell', type: 'command', nonce: 'a'.repeat(96), command: 'play' },
});

print(JSON.stringify({ calls: playerCalls, messages: playerMessages }));
