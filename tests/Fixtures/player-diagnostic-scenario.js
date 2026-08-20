print(JSON.stringify({
    visible: diagnosticElement.hidden === false,
    hasText: diagnosticElement.textContent.includes('replay not ready'),
    sentDiagnostic: diagnosticMessages.length === 1
        && diagnosticMessages[0].message.type === 'diagnostic'
        && diagnosticMessages[0].message.code === 'replay_not_ready',
}));
