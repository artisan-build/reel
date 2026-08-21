const noConsentStart = window.Reel.start({});
const firstStart = window.Reel.start({ consent: true });
const secondStart = window.Reel.start({ consent: true });
const sameStartPromise = firstStart === secondStart;

Promise.all([noConsentStart, firstStart, secondStart]).then(async function (results) {
    const sequentialStart = await window.Reel.start({ consent: true });
    const fetchReceiver = { receiver: 'host-fetch' };
    const fetchResult = window.fetch.call(fetchReceiver, '/host-request', { method: 'PATCH', body: 'host-body' });
    const xhr = new XMLHttpRequest();
    const xhrOpenResult = xhr.open('POST', '/host-xhr', true);
    const xhrSendResult = xhr.send('host-xhr-body');
    const originalOptions = { method: 'PATCH', body: 'host-body', headers: { 'X-Host': 'preserved' } };
    const correlatedFetch = window.fetch('/host-request', originalOptions);
    const rejectedFetch = window.fetch('/host-reject');
    const disabledResponse = await reelHarness.originalFetch('/host-request', { method: 'PATCH', body: 'host-body' });
    const enabledResponse = await correlatedFetch;
    const enabledBodyUnused = enabledResponse.bodyUsed === false;
    const enabledBody = await enabledResponse.text();
    const disabledBody = await disabledResponse.text();
    let rejectedWithSameError = false;
    try { await rejectedFetch; } catch (error) { rejectedWithSameError = error === reelHarness.rejectedError; }

    await window.fetch('/host-error', { method: 'POST', body: 'private-request-body' });
    await window.fetch('/livewire/update', { method: 'POST', headers: { 'X-Livewire': 'true' } });
    await window.fetch('/host-server-error');
    await window.fetch('https://other.example/error');
    const xhrError = new XMLHttpRequest();
    xhrError.open('PUT', '/host-xhr-error');
    const xhrBodyBefore = xhrError.responseText;
    xhrError.send('private-xhr-body');
    const xhrServerError = new XMLHttpRequest();
    xhrServerError.open('GET', '/host-xhr-server-error');
    xhrServerError.send();
    const crossOriginXhr = new XMLHttpRequest();
    crossOriginXhr.open('GET', 'https://other.example/xhr-error');
    crossOriginXhr.send();
    const initial = {
        noConsent: results[0],
        sequential: sequentialStart,
        sameStartPromise: sameStartPromise,
        grantRequests: reelHarness.grantRequests(),
        recordCalls: reelHarness.recordCalls(),
        intervalCalls: reelHarness.intervals.length,
        bufferedTypes: window.Reel.__testing.state().events.map(function (event) { return event.type; })
    };
    const nonInterference = {
        fetchSameObject: fetchResult === reelHarness.hostFetchResults[0],
        fetchReceiverPreserved: reelHarness.hostFetchCalls[0].receiver === fetchReceiver,
        fetchArgumentsPreserved: reelHarness.hostFetchCalls[0].url === '/host-request'
            && reelHarness.hostFetchCalls[0].options.method === 'PATCH'
            && reelHarness.hostFetchCalls[0].options.body === 'host-body',
        fetchPromisePreserved: correlatedFetch === reelHarness.hostFetchResults[1],
        fetchRejectionPromisePreserved: rejectedFetch === reelHarness.rejectedFetchResult,
        fetchRejectionPreserved: rejectedWithSameError,
        fetchBodyUnconsumed: enabledBodyUnused,
        fetchBodyByteIdentical: enabledBody === disabledBody && enabledBody === reelHarness.responseBody,
        callerHeadersUnchanged: originalOptions.headers['X-Reel-Session'] === undefined,
        xhrOpenSameObject: xhrOpenResult === reelHarness.xhrOpenResult,
        xhrSendSameObject: xhrSendResult === reelHarness.xhrSendResult,
        xhrReceiverPreserved: reelHarness.xhrOpenCalls[0].receiver === xhr
            && reelHarness.xhrSendCalls[0].receiver === xhr,
        xhrArgumentsPreserved: JSON.stringify(reelHarness.xhrOpenCalls[0].arguments) === JSON.stringify(['POST', '/host-xhr', true])
            && JSON.stringify(reelHarness.xhrSendCalls[0].arguments) === JSON.stringify(['host-xhr-body']),
        xhrBodyByteIdentical: xhrError.responseText === xhrBodyBefore
    };

    const requestHeaders = {
        fetch: reelHarness.hostFetchCalls.filter(function (call) { return call.url === '/host-request'; })[0].options.headers.get('X-Reel-Session'),
        fetchGrant: reelHarness.hostFetchCalls.filter(function (call) { return call.url === '/host-request'; })[0].options.headers.get('Authorization'),
        xhr: xhr._headers['x-reel-session'] || null,
        xhrGrant: xhr._headers.authorization || null,
        livewire: reelHarness.hostFetchCalls.filter(function (call) { return call.url === '/livewire/update'; })[0].options.headers.get('X-Reel-Session'),
        crossFetch: reelHarness.hostFetchCalls.filter(function (call) { return call.url === 'https://other.example/error'; })[0].options
            ? reelHarness.hostFetchCalls.filter(function (call) { return call.url === 'https://other.example/error'; })[0].options.headers || null
            : null,
        crossXhr: crossOriginXhr._headers['x-reel-session'] || null
    };
    const errorMarkers = window.Reel.__testing.state().events.filter(function (event) {
        return event.type === 5;
    });

    await window.Reel.__testing.flush(false);
    reelHarness.emit({ type: 3, timestamp: Date.now() + 3, data: { source: 5, text: 'input-secret' } });
    await window.Reel.__testing.flush(false);

    window.Reel.__testing.inspectResponse({ status: 200, headers: { get: function () { return 'hidden'; } } }, 'GET', '/billing');
    const hiddenStatus = await window.Reel.start({ consent: true });
    const hidden = {
        status: hiddenStatus,
        grantRequests: reelHarness.grantRequests(),
        recordCalls: reelHarness.recordCalls(),
        storedSession: reelHarness.storage.get('artisan-build.reel.session') || null
    };

    window.Reel.__testing.inspectResponse({ status: 200, headers: { get: function () { return 'allowed'; } } }, 'GET', '/dashboard');
    const restarted = await window.Reel.start({ consent: true });
    await window.Reel.__testing.flush(false);
    const activeExpiry = reelHarness.timeouts.filter(function (timer) { return timer.active; }).pop();
    activeExpiry.callback();

    print(JSON.stringify({
        initial: initial,
        nonInterference: nonInterference,
        requestHeaders: requestHeaders,
        uploadGrant: reelHarness.grant(),
        errorMarkers: errorMarkers,
        hidden: hidden,
        restarted: restarted,
        expired: window.Reel.status(),
        grantRequests: reelHarness.grantRequests(),
        recordCalls: reelHarness.recordCalls(),
        intervalCalls: reelHarness.intervals.length,
        uploads: reelHarness.uploads
    }));
}).catch(function (error) {
    throw error;
});
