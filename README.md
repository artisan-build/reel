<p align="center">
  <img src="art/icon.png" alt="reel icon" width="128">
</p>

<h1 align="center">Reel</h1>

<p align="center"><strong>See what a user's browser did before the bug — recorded into infrastructure you own.</strong></p>

Reel is browser session replay for Laravel applications. A small recorder in your application captures a
privacy-filtered copy of what happened on the page. It does not record video or take screenshots; it records
the page's *DOM* — the structure the browser builds from your HTML — plus the changes, clicks and scrolls that
happened to it, and Reel rebuilds the page from that stream when you replay it.

When one of your own requests returns a 5xx while a session is being recorded, Reel puts a marker on that
session's timeline with the method, path and status, so you can jump straight to the moment it broke. That
works as soon as you are recording. The reverse link — an exception in Nightwatch or Hone carrying a URL back
to the recording — is off by default and is switched on with `REEL_CONTEXT_EXPORT` (see
[Connect a Laravel application](#9-connect-a-laravel-application)).

Reel replaces the one thing most teams actually use a hosted session replay service for: *show me what this
user did before the 500*. It is deliberately not an analytics suite. There are no heatmaps, no funnels, no
native mobile SDKs, and no console logs or network bodies in the recording.

Reel is open source and MIT licensed. Recordings are written to storage in your own
[Laravel Cloud](https://cloud.laravel.com) account and are never sent to Artisan Build, which runs no server
of its own in the recording path and charges nothing per session. Your Laravel Cloud bill is the only thing
that grows with usage.

> **Status: experimental.** Reel runs, but it has not finished the browser, privacy, performance, cost, and
> retention evidence it needs to be a supported product. Read the
> [known limitations](docs/limitations.md) before you record real users, and start with a consented pilot.

## The easy way: Scalpels

[Scalpels](https://scalpels.app/products/reel) installs and deploys Built for Cloud applications for you. Buy
Reel there and it forks this repository into your own GitHub organisation, creates the Laravel Cloud resources
Reel needs, connects them, and deploys it.

The GitHub repository and the Laravel Cloud resources are created in your own accounts and stay yours; Scalpels
does the setup described below rather than hosting anything on your behalf. Everything that follows is that
setup, done by hand.

## Run it yourself

### What you need

- **PHP 8.3 or newer**, with the `pdo_pgsql` extension. CI runs the suite on 8.4 and 8.5.
- **Composer.**
- **Git.**
- **A PostgreSQL server** you can create databases on, **and the `psql` client**. Several migrations use
  PostgreSQL-specific SQL, so MySQL and SQLite are not supported.
- **A Laravel Cloud account** and its `cloud` CLI, when you reach the deploy step.

Check the three that are easy to be missing:

```bash
php -v                                   # 8.3 or newer
php -m | grep pdo_pgsql                  # must print: pdo_pgsql
git --version && psql --version && composer --version
```

If `php -m | grep pdo_pgsql` prints nothing, migrations will fail with "could not find driver". Install your
platform's PHP PostgreSQL extension before going further. A running PostgreSQL server does not put either the
extension or the `psql` client on your machine.

You do *not* need Node, npm, or Vite. Reel has no frontend build step — the compiled CSS is committed under
`public/build/assets/`.

### 1. Get the code and install dependencies

```bash
git clone https://github.com/artisan-build/reel.git
cd reel
composer install
```

### 2. Create your environment file

```bash
cp .env.example .env
php artisan key:generate
```

`key:generate` writes an `APP_KEY` into `.env`. Laravel uses it to encrypt cookies and stored secrets, so
every install needs its own.

### 3. Create two databases

Reel uses one database for development and a second one for the test suite. The development one can be called
anything; `reel` is the name `.env.example` already contains.

```bash
psql -h 127.0.0.1 -U <your-postgres-role> -d postgres -c 'CREATE DATABASE reel;'
psql -h 127.0.0.1 -U <your-postgres-role> -d postgres -c 'CREATE DATABASE reel_app_test;'
```

Set `DB_DATABASE` in `.env` to the first name, and `DB_USERNAME` / `DB_PASSWORD` to that role.

The **test** database name is not yours to choose. The suite ignores `.env` entirely and uses the values
pinned in [`phpunit.xml`](phpunit.xml): database `reel_app_test` on `127.0.0.1:5432`, as the role `root` with
an empty password. It is separate because the suite resets it between tests. If your PostgreSQL server has no
`root` role, either create one or change those values in `phpunit.xml`.

### 4. Run the migrations

```bash
php artisan migrate
```

That runs 50 migrations and leaves 50 tables in your development database: Reel's own applications, recording
sessions, chunks, and retention tables, plus the users, credentials, and authority tables owned by the Built
for Cloud package. The last line should be
`2026_09_21_000002_create_managed_enrolment_requests_table .. DONE`.

### 5. Create the first administrator

```bash
php artisan create-admin --local
```

It asks four questions in order — **Email**, **Name**, **Password**, **Confirm password** — and then creates
the installation's single **Owner**. The password must be at least 8 characters. If the two password entries
do not match the command prints `Passwords do not match.` and exits without creating anything; it does not ask
again, so just run it a second time. On success it prints `Admin user <email> created.` — it says "Admin" even
though this first user is the Owner.

Once an Owner exists the command refuses to run again; `--force` then creates an Admin instead of a second
Owner.

> **`--local` is not optional here.** Without `--local`, `create-admin` asks whether to create the user on
> this machine *or in one of your Laravel Cloud environments*, and can create a real administrator in
> production.
>
> Exactly three Built for Cloud commands behave this way, because only they can forward themselves to Laravel
> Cloud: `create-admin`, `bfc:ownership:mint-claim`, and `bfc:ownership:remint-owner-token`. The credential
> commands (`bfc:credential:*`) take `--local` for a different reason — they *refuse to run at all* without it
> and never reach Cloud. The remaining `bfc:*` commands take no `--local` option and act on whatever
> environment Artisan is already running in. Reel's own `reel:*` commands likewise always run where you run
> them. Before any state-changing Built for Cloud command, run `php artisan help <command>` and look at which
> of `--local` and `--environment` it accepts.

### 6. Run the tests

```bash
composer test
```

This clears the config cache, checks formatting with Pint, runs the application suite against
`reel_app_test`, then runs the client package's own suite. A green run means your PHP version, PostgreSQL
connection, and install are all sound.

The recorder's security tests execute real JavaScript through JavaScriptCore. macOS ships the `jsc` binary
inside the system JavaScriptCore framework and the tests find it there automatically. On Linux, install it
first (`libjavascriptcoregtk-4.1-bin`), or point `JSC_BINARY` at your own build — without it those tests
fail rather than skip.

### 7. Start the application

```bash
composer dev
```

That serves Reel at <http://localhost:8000>, or the next free port if 8000 is taken — read the URL it
prints. You should see a Reel landing page.

Sign in at `/bfc/login` with the Owner you just created. Built for Cloud owns login, password reset,
invitations, and session management, and they all live under `/bfc`. Reel itself defines no login screen and
no user model. After signing in, Reel's own screens are at `/dashboard`, `/applications`, and `/sessions`.

### 8. Deploy to Laravel Cloud

Reel is built to run on Laravel Cloud, and [`built-for-cloud.json`](built-for-cloud.json) declares exactly
what it needs: one compute application, a PostgreSQL database, a **private** Laravel Object Storage bucket, a
managed queue, and the scheduler. It needs no paid cache.

> ### ⛔ Never set environment variables for resources Cloud provisions
>
> When you attach a database, cache, queue, or bucket, Laravel Cloud writes that resource's configuration
> into its own managed environment file and injects it at runtime. That includes the connection selectors,
> not just the credentials: `DB_CONNECTION`, `CACHE_STORE`, `QUEUE_CONNECTION`, `FILESYSTEM_DISK`, and every
> `DB_*`, `AWS_*`, and `SQS_*` value.
>
> **If you set any of those yourself, your value shadows the injected one and the resource breaks.** Set only
> genuinely app-specific variables in Cloud: `APP_ENV`, `APP_DEBUG`, `APP_NAME`, `APP_URL`. Let Cloud manage
> `APP_KEY`.
>
> `.env.example` in this repository is local development configuration, not deployment configuration. Never
> copy it into a Cloud environment, and never run `composer setup` as part of a deploy.

> **`built-for-cloud.json` does not configure anything by itself.** It is a description of Reel, written for
> installer tools like Scalpels to read. When you deploy by hand, nothing reads it — you do, and you type its
> contents into Laravel Cloud yourself. Step 6 below is that step. Skip it and your deployment will never run
> its migrations and never run its readiness check.

These commands are the Laravel Cloud CLI as of v0.5.0. It changes; run `cloud <command> -h` before you trust a
signature here. Pass `-n` (non-interactive) on everything except `cloud ship`, and `--json` on reads and
creates.

**0. Install and authenticate the `cloud` CLI.** Follow the install instructions at
<https://cloud.laravel.com>, then sign in:

```bash
cloud auth                      # interactive, opens a browser
cloud auth:token --add          # headless / CI instead
```

**1. Fork this repository** into your own GitHub organisation, and clone your fork.

**2. Bootstrap the application** from inside your clone:

```bash
cloud ship
```

This one is interactive on purpose. Walk its prompts: region → application name → your forked repository →
**create the PostgreSQL database** → enable the scheduler. Let `ship` create the database: it is the only path
that reliably *attaches* it as well. Say yes to the scheduler — Reel's finalization, retention and erasure
work only runs if it is on.

**3. Bind the repository to the application:**

```bash
cloud repo:config
```

`ship` does not write `.cloud/config.json` itself, and without that file every later `cloud` command has no
idea which application you mean.

**4. Create the private object storage bucket.** `bucket:create` requires all six of these flags and will
error on one missing flag at a time until they are all present:

```bash
cloud bucket:create \
  --name reel-production \
  --region <the region you chose in step 2> \
  --visibility private \
  --key-name reel-production \
  --key-permission read_write \
  --allowed-origins "https://<your-reel-hostname>" \
  -n --json
```

Keep `--visibility private`: Reel serves replay data through its own authenticated player and must never be
readable from a public bucket URL. Scope `--allowed-origins` to the real hostname, never `*`.

**Then attach it in the dashboard** — Environment → Storage → attach the bucket. There is no `--bucket-id`
flag on `environment:update`, so this step cannot be scripted. **Checkpoint:** the environment's Storage panel
lists the bucket before you go on.

**5. Create the managed queue and make it the default:**

```bash
cloud managed-queue:create <environment> --name reel --size <size> -n --json
cloud managed-queue:set-default <environment> --name reel -n
```

Use a managed queue rather than a long-running worker instance. Reel's compaction, deletion and erasure jobs
all run on it.

**Checkpoint:** the database is attached too. `cloud ship` does attach it, but the CLI cannot tell you so —
`environment:get --fields=databaseSchemaId` reads back `null` even when it is attached. Confirm in the
dashboard (Environment → Database), not from the CLI.

**6. Copy the manifest's build and deploy commands into Laravel Cloud.** Open Environment → Settings and set
them to exactly what [`built-for-cloud.json`](built-for-cloud.json) declares:

- Build commands:

  ```
  composer install --no-dev --optimize-autoloader --no-interaction
  php artisan optimize
  ```

- Deploy (post-deploy) commands:

  ```
  php artisan migrate --force
  php artisan reel:smoke
  ```

If you leave the deploy commands empty, your schema is never created and nothing tells you the deployment is
broken.

**7. Set the application variables** — and only these:

```bash
cloud environment:variables --json -n --action=set --key=APP_ENV   --value=production
cloud environment:variables --json -n --action=set --key=APP_DEBUG --value=false
cloud environment:variables --json -n --action=set --key=APP_NAME  --value=Reel
cloud environment:variables --json -n --action=set --key=APP_URL   --value=https://<your-reel-hostname>
```

Let Cloud generate and keep `APP_KEY`. Set nothing else — re-read the box above.

**8. Deploy and watch it:**

```bash
cloud deploy -n
cloud deploy:monitor -n
```

**9. Read the `reel:smoke` output in the deploy log.** It runs last, after `migrate --force`. It refuses to
report ready unless the resolved disk is S3-backed and the queue is not inline, so a failure here means a
resource is missing or an environment variable is shadowing one — not a cosmetic warning. Fix it before you
enroll anything.

`reel:smoke` does **not** prove the Cloud scheduler is switched on or firing: its scheduler check only
compares the schedule this application registers in code against `built-for-cloud.json`. Confirm separately in
the dashboard that the environment's scheduler is enabled, and that its runs are appearing, or Reel's
finalization and retention work silently never happens and recordings never become playable.

**10. Create the first Owner in the deployed environment:**

```bash
php artisan create-admin --environment=<environment>
```

Your machine collects the password and hashes it locally; only the hash travels to Cloud. Do not print, paste
or store either the password or the hash.

Attaching the database and attaching the bucket are the two steps the Laravel Cloud CLI cannot do today; do
them in the dashboard and confirm there rather than trusting a CLI read.

Full deployment, recovery, upgrade, rotation, backup, and uninstall procedures are in
[`docs/deployment.md`](docs/deployment.md).

### 9. Connect a Laravel application

1. Sign in to your Reel deployment and open **Applications → Create application**. Give it a name, one
   allowed origin per line (`https://app.example.com` — no paths or query strings), and a sampling percent.
2. Copy the **enrollment code** shown on the next screen. An enrollment code is a one-time secret that proves
   to Reel that the application registering a signing key really is the one you just created. It expires in
   15 minutes, Reel stores only a hash of it, and it is never shown again.
3. **Install the Reel client into the application you want to record.**

   `artisan-build/reel-client` is **not on Packagist yet**, and it cannot be installed from a Git URL either:
   it lives inside this repository, whose root package is `artisan-build/reel`, and Composer cannot install a
   package from a subdirectory of another one. Until it is split out and published, install it from a local
   clone of this repository.

   ```bash
   # once, anywhere you like — this clone is only a source of files
   git clone https://github.com/artisan-build/reel.git ~/src/reel

   # then, in the application you want to record
   composer config repositories.reel-client \
     '{"type":"path","url":"/absolute/path/to/src/reel/packages/reel-client","options":{"symlink":false}}'
   composer require artisan-build/reel-client
   ```

   `"symlink": false` matters. A Composer path repository symlinks by default, and a symlink into a directory
   on your laptop will not exist on the server you deploy to. With `"symlink": false` Composer **copies** the
   package into `vendor/`, so your `composer.lock` and your deployment both work — but you must re-run
   `composer update artisan-build/reel-client` from an updated clone to pick up a new version. Use an absolute
   path; a relative one is resolved against the application directory.

   Then enroll:

   ```bash
   php artisan reel:install
   ```

   It asks for the Reel URL, the application id, the enrollment code, and a Reel Context export mode — answer
   `off` for that last one unless you want the Nightwatch link described below. You can pass them instead:

   ```bash
   php artisan reel:install --url=https://reel.example.com \
     --application=<application id> --enrollment-code=<code> --context-export=off
   ```

   On success it prints `Reel enrolled. The signing key remains local to this application.` The installer
   generates an RSA key pair inside your application, sends only the **public** key to Reel's enrollment
   endpoint (`POST /bfc/asymmetric-enrollments/{application}`), and writes `REEL_URL`,
   `REEL_APPLICATION_ID`, `REEL_PRIVATE_KEY`, and `REEL_CONTEXT_EXPORT` to that application's `.env`. The
   private key never leaves your application; Reel keeps only the public key and uses it to check that each
   upload was signed by you.

4. **Hide sensitive routes before you record anything.** The exclusion is absolute for that response:

   ```php
   Route::get('/billing/payment-method', PaymentMethodController::class)->hiddenFromReel();

   Route::hiddenFromReel()->group(function (): void {
       require __DIR__.'/auth.php';
   });
   ```

   Review authentication, password recovery, card entry, health data, and privileged admin routes. Sensitive
   content inside an otherwise recordable page still needs `data-reel-mask`, `data-reel-block`, or configured
   selectors.

5. **Add the recorder to your layout.** It loads the assets and nothing else — it does not start recording:

   ```blade
   <x-reel::recorder />
   ```

   The client registers its own routes as soon as `REEL_URL` and `REEL_PRIVATE_KEY` are both set. Confirm
   with `php artisan route:list | grep reel` — you should see `reel/session-grants` and the two
   `reel/assets/...` routes.

6. **Start recording only after your own consent decision.** `Reel.start()` returns a promise resolving to a
   status object, so put it in an async function and check what you got back:

   ```js
   document.querySelector('#accept-cookies').addEventListener('click', async () => {
       const status = await Reel.start({ consent: true, refuseOnGpc: true });
       console.log(status.state);   // "recording" when it is actually recording
   });
   ```

   `state` is `recording` on success. The other values tell you why it did not start: `awaiting_consent` (you
   did not pass `consent: true`), `refused_gpc` (the browser sends Global Privacy Control and you passed
   `refuseOnGpc: true`), `hidden` (the page is on a route marked `hiddenFromReel()`), or `stopped`. You can
   read it again at any time with `Reel.status()`, and stop with `Reel.stop()`.

#### What to expect the first time

A recording does **not** become playable as soon as you stop clicking. Reel closes and assembles sessions on
the scheduler, in stages:

| Session status | What it means | How long |
| --- | --- | --- |
| `recording` | Chunks are arriving. Appears in **Sessions** almost immediately. | while you interact |
| `closing` | No chunk has arrived for 15 minutes, so Reel closed the session and is waiting for stragglers. | 2 more minutes |
| `compacting` | A queued job is assembling the chunks into one replayable object. | seconds to minutes |
| `ready` | Playable. | — |

So budget roughly **17 minutes plus compaction** between your last click and a replay you can open, and make
sure the queue worker and scheduler are actually running — locally that means `php artisan schedule:work` and
`php artisan queue:work` in their own terminals. The player refuses any session that is not `ready`; seeing
`closing` or `compacting` is normal progress, not a failure.

Once a session is `ready`, `Reel::sessionsUrlFor($model)` builds a filtered Reel link from an
application-scoped primary key. Those links are filters, not access credentials — anyone opening one still
has to sign in to Reel.

## Configuration

Reel reads very little from the environment. Most of its behaviour is set in config files you edit and commit.

### Environment variables (the Reel server)

| Variable | Default | What it does |
| --- | --- | --- |
| `APP_KEY` | *(none)* | Laravel's encryption key. Required. |
| `APP_URL` | `http://localhost` | The base URL clients enroll against. |
| `DB_CONNECTION` | `pgsql` | Must stay PostgreSQL. **Do not set in Laravel Cloud.** |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | local values | Development database. **Do not set in Laravel Cloud.** |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION`, `FILESYSTEM_DISK` | `database`/`database`/`database`/`local` | Local development drivers. **Do not set in Laravel Cloud.** |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_BUCKET` | unset | Object storage credentials and bucket. **Do not set in Laravel Cloud.** |
| `AWS_DEFAULT_REGION` | `us-east-1` | Object storage region. **Do not set in Laravel Cloud.** |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `false` | Path-style S3 addressing. **Do not set in Laravel Cloud.** |
| `BUILT_FOR_CLOUD_CREDENTIAL_GUARD` | `bfc` | The auth guard used to resolve presented credentials. Leave it alone unless you know why you are changing it. |

### Config files

| File | Key settings |
| --- | --- |
| [`config/reel_ingest.php`](config/reel_ingest.php) | Upload limits and session timing: `maximum_request_bytes` (384 KB), `maximum_decompressed_chunk_bytes` (2 MB), `maximum_grant_lifetime_seconds` (31 minutes), `abandoned_after_seconds` (15 minutes), `late_arrival_window_seconds` (2 minutes), `object_prefix` (`reel/chunks`). |
| [`config/reel_retention.php`](config/reel_retention.php) | `ordinary_days` (30), `unprotect_cooling_hours` (72), `orphan_safety_delay_hours` (24). |
| [`config/replay.php`](config/replay.php) | Player ceilings: `maximum_compressed_object_bytes` (64 MB), `maximum_decompressed_bytes` (128 MB). |
| [`config/built-for-cloud.php`](config/built-for-cloud.php) | The catalog manifest and which Built for Cloud screens are enabled. `credentials.app_purposes` maps Reel's `reel.application.signing` operation to the package's `signing` **purpose** — the fixed label that decides what a credential is allowed to be used for. |

### Environment variables (a monitored application)

`php artisan reel:install` writes these into the application you are recording. `REEL_PRIVATE_KEY` is a
secret: treat it like a database password.

| Variable | What it does |
| --- | --- |
| `REEL_URL` | Base URL of your Reel deployment. |
| `REEL_APPLICATION_ID` | The public id of the Reel application this app enrolled as. |
| `REEL_PRIVATE_KEY` | The signing key that authorises uploads. Never leaves the application. |
| `REEL_CONTEXT_EXPORT` | `off`, `session_id`, or `session_id_and_url`. Controls whether the session id rides along in Laravel Context to your Nightwatch transport. |
| `REEL_RELEASE_ID` | Optional release marker recorded with each session. |
| `REEL_HOST_MODE` | Optional override. The client turns itself on automatically when `REEL_URL` and `REEL_PRIVATE_KEY` are both set. |

## Scheduled work

Reel does its housekeeping on the scheduler, so the Laravel Cloud scheduler must be enabled or recordings
never become playable and nothing ever expires. These are the exact command strings that are scheduled, in
[`routes/console.php`](routes/console.php) and [`built-for-cloud.json`](built-for-cloud.json) — note that two
of them carry `--apply`, so in production they mutate on every run rather than reporting:

| Scheduled command | Runs | What it does | Deletes data? |
| --- | --- | --- | --- |
| `reel:finalize-sessions` | every minute | Closes sessions with no recent chunk and queues compaction. | no |
| `reel:retain-sessions` | hourly | **Deletes** unprotected sessions past their retention deadline. | **yes** |
| `reel:retry-deletions --apply` | hourly | Retries object deletions and tombstones that did not complete. | **yes** |
| `reel:resume-erasures --apply` | every 5 minutes | Dispatches the remaining batches of a user-erasure request. | **yes** |
| `reel:sweep-orphans` | daily | **Deletes** stored objects that have no live database row. | **yes** |

Without `--apply`, `reel:retry-deletions` and `reel:resume-erasures` only report what they would do. The
scheduler does not run them that way.

`reel:reconcile-storage` is a manual diagnostic, and is a dry run unless you pass an explicit mutation flag.

> **Running these locally is a good way to understand them, but four of the five delete things.** Point your
> `.env` at a throwaway database and a throwaway `FILESYSTEM_DISK` before experimenting, never at a database
> or bucket whose recordings you want to keep.

## Troubleshooting

**`reel:smoke` fails locally with "The configured filesystem driver [local] is not S3-backed".**
That is correct behaviour. `reel:smoke` is a *deployment* check and only passes against real S3-backed object
storage and a real queue. Do not expect it to go green on your laptop. Note also what a green `reel:smoke`
does and does not prove: it checks the database, the object storage disk and the queue for real, but its
scheduler check only compares the schedule the application registers in code against `built-for-cloud.json`.
It never asks Laravel Cloud whether the scheduler is enabled or firing — confirm that in the dashboard.

**`create-admin` asks me where to create the user, and offers Laravel Cloud environments.**
Add `--local`. See step 5.

**`composer test` cannot connect to the database.**
The suite ignores `.env` and uses the values pinned in `phpunit.xml` — database `reel_app_test`, role `root`,
empty password, `127.0.0.1:5432`. Create that database and role, or edit `phpunit.xml`.

**`composer require artisan-build/reel-client` fails with "Could not find a matching version of package".**
The client is not on Packagist yet, and Composer cannot pull it out of this repository over Git either. Add
it as a path repository from a local clone first — see step 3 of
[Connect a Laravel application](#9-connect-a-laravel-application). If you already did that and still get the
error, check that the `url` you configured is an absolute path ending in `/packages/reel-client` and that the
directory contains a `composer.json`.

**The client works locally but `vendor/artisan-build/reel-client` is missing after a deploy.**
Your path repository is symlinking. Re-add it with `"options": {"symlink": false}` so Composer copies the
package into `vendor/`, then `composer update artisan-build/reel-client` and commit the lock file.

**Sessions never appear in Reel.**
Check, in order: the recording application's origin exactly matches one of the application's allowed origins
(scheme and host, no trailing path); your application calls `Reel.start()` after its consent decision; the
route is not marked `hiddenFromReel()`; and ingest is still enabled for that application in its Reel
settings. Uploads are rejected with a short reason such as `origin_mismatch` or `application_disabled`.

**A replay looks wrong — images and fonts are missing.**
Playback is deliberately network-free. External images, media, fonts, stylesheets, and iframe content are
replaced by placeholders so that replaying a session never calls out to anything. See
[known limitations](docs/limitations.md).

## Development

`composer ready` is the full check, and it has to pass on a clean, committed tree before a pull request is
reviewed. It stops at the first failure. In order, it runs:

1. the IDE helper generators (which rewrite `_ide_helper.php`, `_ide_helper_models.php` and
   `.phpstorm.meta.php`);
2. `rector process`;
3. `pint --parallel`;
4. `phpstan analyse` (Larastan, level 6);
5. `composer test`;
6. `composer audit` for `packages/reel-client` and then for the application.

```bash
composer ready
```

Steps 1–3 **rewrite files**, so expect a dirty tree afterwards and commit what they changed.

The smaller scripts, by what each one actually invokes:

| Script | Runs |
| --- | --- |
| `composer lint` | `pint --parallel` (rewrites files) |
| `composer lint:check` | `pint --parallel --test` (reports only) |
| `composer stan` | `phpstan analyse --memory-limit=512M` |
| `composer test` | `config:clear`, `pint --parallel --test`, `php artisan test`, then the `packages/reel-client` suite |
| `composer report` | Rector, Pint check, PHPStan, `php artisan test`, `composer audit` — each non-blocking, printed section by section. It does **not** run the IDE helpers, the client package suite, or the client package audit, so it is a quick overview rather than a substitute for `composer ready`. |

CI runs a subset on every push and pull request to `main`. It does **not** run the IDE helpers or Rector, so
`composer ready` can still find work on a branch CI called green:

| Workflow | Job | Runs |
| --- | --- | --- |
| [`.github/workflows/tests.yml`](.github/workflows/tests.yml) | `ci` (PHP 8.4 and 8.5, PostgreSQL 16) | `composer stan`, `composer test`, then `composer audit` for the client package and the application |
| [`.github/workflows/lint.yml`](.github/workflows/lint.yml) | `quality` (PHP 8.5) | `composer lint:check` |

Feature work in this repository is built by an automated process; [`.solo/workflow.md`](.solo/workflow.md)
describes it and is aimed at that tooling rather than at contributors. If you are sending a pull request, the
part that applies to you is the list above: make `composer ready` pass, and commit anything it rewrote.

## Where things are

| | |
| --- | --- |
| [`docs/product/reel-prd.md`](docs/product/reel-prd.md) | The full product definition, scope, and decisions. |
| [`docs/deployment.md`](docs/deployment.md) | Deployment, authority, rotation, backup, and uninstall. |
| [`docs/limitations.md`](docs/limitations.md) | What Reel does not do. Read before recording real users. |
| [`docs/retention.md`](docs/retention.md) | Retention, protection, and deletion behaviour. |
| [`docs/runbooks/`](docs/runbooks) | What to do when compaction stalls, chunks are rejected, storage grows, retention fails, or a restore leaves uncertainty. |
| [`packages/reel-client`](packages/reel-client) | The Laravel client installed into monitored applications. |

## Stack

PHP 8.3+ · [Laravel](https://laravel.com) 13 · Livewire 4 + Flux · PostgreSQL ·
[`artisan-build/built-for-cloud`](https://github.com/artisan-build/built-for-cloud) v0.16 for users, roles,
and credentials · nodeless assets via `laravel/chisel`. Scaffolded from the
[`artisan-build/laravel-nodeless`](https://github.com/artisan-build/laravel-nodeless) starter kit.

## License

Reel is open-source software licensed under the [MIT license](LICENSE).
