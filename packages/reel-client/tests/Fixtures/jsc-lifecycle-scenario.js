const noConsentStart = window.Reel.start({});
const firstStart = window.Reel.start({ consent: true });
const secondStart = window.Reel.start({ consent: true });
const sameStartPromise = firstStart === secondStart;

Promise.all([noConsentStart, firstStart, secondStart]).then(async function (results) {
    const initial = {
        noConsent: results[0],
        sameStartPromise: sameStartPromise,
        grantRequests: reelHarness.grantRequests(),
        recordCalls: reelHarness.recordCalls(),
        intervalCalls: reelHarness.intervals.length,
        bufferedTypes: window.Reel.__testing.state().events.map(function (event) { return event.type; })
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
