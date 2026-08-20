window.Reel.start({ consent: true }).then(async function () {
    reelHarness.redirectUploads();
    await window.Reel.__testing.flush(false);

    print(JSON.stringify({
        redirect: reelHarness.uploadOptions[0] && reelHarness.uploadOptions[0].redirect,
        redirectTargets: reelHarness.redirectTargets,
        pendingUploads: window.Reel.status().pendingUploads
    }));
}).catch(function (error) {
    throw error;
});
