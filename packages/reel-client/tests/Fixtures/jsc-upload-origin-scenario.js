reelHarness.grantUploadUrl('https://host.example/capture');

window.Reel.start({ consent: true }).then(function (status) {
    print(JSON.stringify({
        status: status,
        storedSession: reelHarness.storage.get('artisan-build.reel.session') || null,
        redirectTargets: reelHarness.redirectTargets
    }));
}).catch(function (error) {
    throw error;
});
