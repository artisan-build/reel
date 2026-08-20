# Reel Laravel client

The Reel client enrolls a Laravel application and serves the pinned browser recorder without Node or a
frontend build.

## Install

```bash
composer require artisan-build/reel-client
php artisan reel:install
```

The installer generates an RSA private key in the host process, sends only its public key to the Reel
enrollment endpoint, and updates only `REEL_URL`, `REEL_APPLICATION_ID`, and `REEL_PRIVATE_KEY` in `.env`.

Place the component in the document layout. It loads the static assets but does not begin recording:

```blade
<x-reel::recorder />
```

The host owns consent and starts Reel explicitly:

```js
await Reel.start({ consent: true });
```

To make Global Privacy Control an automatic refusal in the host's consent decision:

```js
await Reel.start({ consent: true, refuseOnGpc: true });
```

Mark every wholly sensitive route or group explicitly. The exclusion is absolute for that response:

```php
Route::get('/billing/payment-method', PaymentMethodController::class)->hiddenFromReel();

Route::hiddenFromReel()->group(function (): void {
    require __DIR__.'/auth.php';
});
```

Use `reel.hidden` for routes whose registration API cannot use the fluent macro. Review authentication,
credential recovery, payment-card entry, health data, and privileged administration routes before enabling
real-user recording. Sensitive content rendered within an otherwise allowed route still needs
`data-reel-mask`, `data-reel-block`, configured selectors, or the stronger all-text policy.

`Reel::sessionsUrlFor($model)` and `Reel::sessionsUrlForId($id)` create authenticated Reel filter URLs from
only the application-scoped primary key. These URLs are filters, not access credentials; browser history,
screenshots, and customer-controlled infrastructure logs may retain them.

## Uninstall

Remove the explicit `Reel.start()` call and deployed recorder component before revoking the credential or
removing Reel. Historical recordings do not need to be deleted when the client is removed.
