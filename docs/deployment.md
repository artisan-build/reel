# Deployment and operations

Reel is an experimental Built for Cloud application. Review the [known limitations](limitations.md) before
recording real users.

## Deploy Reel

The catalog control plane should consume `built-for-cloud.json`, connect this repository, and provision exactly
one application compute resource, PostgreSQL database, private Laravel Object Storage bucket, managed queue,
and scheduler. Reel needs no paid cache.

Laravel Cloud injects every attached resource's credentials and connection selectors into its managed
environment file. Do not set `DB_CONNECTION`, `QUEUE_CONNECTION`, `CACHE_STORE`, `FILESYSTEM_DISK`, any `DB_*`,
`AWS_*`, or `SQS_*` value in the Cloud environment. An application-set value shadows Cloud's injected value.
The local `.env.example` is not deployment configuration: never copy it or run `composer setup` during a deploy.

The manifest installs production dependencies and optimizes the app during build. Post-deploy, it runs
`php artisan migrate --force` followed by `php artisan reel:smoke`. The smoke command uses the configured
database, default private disk, queue, and scheduler. It refuses to report ready unless the resolved disk uses
the S3 driver and the queue is non-inline; a failed smoke must block readiness.

## Authority and account access

Built for Cloud owns Reel's users, roles, invitations, password setup/reset, sessions, and managed entry. A
standalone installation uses the package's first-Owner and invitation lifecycle; a managed installation enters
through the control plane's correlated, single-use handoff. Do not bootstrap an app-owned administrator, add a
second local login path, or place a permanent bootstrap credential in environment variables.

Owner, Admin, and Member all have access to Reel's applications, recordings, configuration, signing credential
lifecycle, immediate deletion, and confirmed application-user erasure. The `admin.*` route names are naming
residue only and do not denote an Admin-only policy. Only unprotecting another actor's recording distinguishes
roles: the original opaque actor, an Admin, or an Owner may do that.

Self-service profile editing and account deletion were removed with Reel's app-owned auth screens. Use the
package's supported account/member lifecycle; Reel does not rebuild those two unsupported self-service actions.
For any state-changing Built for Cloud Artisan command used during local recovery or diagnostics, pass `--local`
so it cannot be forwarded to Laravel Cloud unintentionally.

## Create and enroll an application

1. Sign in to Reel and open **Applications**.
2. Create an application with its exact allowed origins, privacy severity, explicit masks/blocks, excluded
   paths, and sampling policy.
3. Copy the short-lived enrollment code when it is shown. The package stores only its hash and displays it once.
4. In the monitored Laravel application, install `artisan-build/reel-client` and run its installer:

```bash
composer require artisan-build/reel-client
php artisan reel:install
```

The installer generates the signing keypair in the monitored application, sends only the public key to the
package enrollment endpoint in Reel, and stores the Reel URL, application id, and private key as host-application
secrets. Add the Reel Blade
component to the host layout, mark sensitive routes with `hiddenFromReel()`, and call `Reel.start()` only after
the host's consent decision and privacy walkthrough.

## Upgrades

Deploy Reel and run its additive migrations before upgrading the recorder whenever a protocol change requires
a server-first order. Confirm `php artisan reel:smoke` passes, then update `artisan-build/reel-client` in the
monitored application. Do not publish a recorder protocol version until the deployed server accepts it.

## Credential rotation

In the application's Reel settings, select **Rotate credential**, copy the package-issued one-time enrollment
code, and run `php artisan reel:install` in the monitored application to enroll a new host-generated public key. Verify new
sessions arrive under the replacement credential before revoking the old credential in Reel. Rotation and
revocation do not delete historical recordings.

## Incident disable

Use the application's **Disable enrollment and ingest** control as the kill switch. It stops enrollment,
session acceptance, and new recording work without deleting existing sessions. Also stop calling `Reel.start()`
in the monitored application when client-side capture must stop immediately. Investigate before re-enabling.

## Export limitations

Reel has no pixel-video export. It stores compressed, versioned DOM-event replay objects and serves them only
through its authenticated player. Database or bucket copies are disaster-recovery material, not a supported
portable replay export, and a manifest without its matching object cannot be replayed.

## Backup and restore

Back up PostgreSQL and the private Object Storage bucket as one consistency set. Before restoring either side,
suspend destructive orphan work:

```bash
php artisan reel:reconcile-storage --suspend
```

Restore the database and bucket together, keep normal access and ingest paused, then follow the
[restore-uncertainty runbook](runbooks/restore-uncertainty.md). A deletion removes data from the live Reel
deployment, not immediately from immutable backups. Backup copies follow the customer's retention policy; if
an older backup is restored, reapply every applicable deletion request before access or ingest resumes.

## Uninstall

1. Stop every monitored application from calling `Reel.start()` and deploy that change first.
2. Disable enrollment and ingest for each Reel application and confirm no new sessions arrive.
3. Remove the client component/middleware and `artisan-build/reel-client` from each monitored application.
4. Revoke application credentials only after recording has stopped.
5. Retain or export the consistent database-and-bucket backup required by the customer's policy.
6. Remove the Reel server and Cloud resources only when historical data is no longer needed.

Uninstalling the recorder does not require deleting historical Reel sessions. Removing the server or bucket
first makes those sessions inaccessible and can strand uploads from hosts that are still recording.
