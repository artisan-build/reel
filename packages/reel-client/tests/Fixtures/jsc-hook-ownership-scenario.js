window.Reel.start({ consent: true }).then(function () {
    const laterFetch = function () { return Promise.resolve({ ok: true, status: 200 }); };
    const laterOpen = function () { return 'later-open'; };
    const laterSend = function () { return 'later-send'; };

    window.fetch = laterFetch;
    XMLHttpRequest.prototype.open = laterOpen;
    XMLHttpRequest.prototype.send = laterSend;
    window.Reel.stop();

    print(JSON.stringify({
        fetchPreserved: window.fetch === laterFetch,
        xhrOpenPreserved: XMLHttpRequest.prototype.open === laterOpen,
        xhrSendPreserved: XMLHttpRequest.prototype.send === laterSend
    }));
}).catch(function (error) {
    throw error;
});
