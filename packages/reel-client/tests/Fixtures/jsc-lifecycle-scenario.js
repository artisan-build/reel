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
        fetchSameObject: fetchResult === reelHarness.hostFetchResult,
        fetchReceiverPreserved: reelHarness.hostFetchCalls[0].receiver === fetchReceiver,
        fetchArgumentsPreserved: reelHarness.hostFetchCalls[0].url === '/host-request'
            && reelHarness.hostFetchCalls[0].options.method === 'PATCH'
            && reelHarness.hostFetchCalls[0].options.body === 'host-body',
        xhrOpenSameObject: xhrOpenResult === reelHarness.xhrOpenResult,
        xhrSendSameObject: xhrSendResult === reelHarness.xhrSendResult,
        xhrReceiverPreserved: reelHarness.xhrOpenCalls[0].receiver === xhr
            && reelHarness.xhrSendCalls[0].receiver === xhr,
        xhrArgumentsPreserved: JSON.stringify(reelHarness.xhrOpenCalls[0].arguments) === JSON.stringify(['POST', '/host-xhr', true])
            && JSON.stringify(reelHarness.xhrSendCalls[0].arguments) === JSON.stringify(['host-xhr-body'])
    };

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
