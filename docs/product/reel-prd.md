<!--
Provenance: copied verbatim into this repository on 2026-08-20 from the Artisan Build brain at
ideas/2026-08-20-reel-prd.md. This in-repo copy is the authoritative plan for Reel's coordinated
build; agents must not depend on brain-only context. Content below is unmodified.
-->

# Reel — Product Requirements Document (v0.1 experimental)

> **Status: DRAFT for red-line — 2026-08-20.** This turns the product exploration in
> `ideas/2026-08-20-bfc-session-replay.md` into a buildable experimental Built for Cloud product.
> It deliberately specifies a real BfC deployment rather than a disposable proof of concept.

## 1. Product definition

**Reel is customer-owned browser session replay for debugging Laravel applications.** It records a
privacy-filtered representation of a user's browser session, stores it in the customer's own Laravel
Cloud account, and lets an authorized person replay what happened before a UI problem or server error.

Reel is not video capture and is not a general product-analytics suite. The browser records DOM snapshots
and changes, pointer and scroll activity, and selected application markers using rrweb. Reel reconstructs
that event stream in its player.

Positioning:

> **Reel — self-hosted, infrastructure-metered session replay on Laravel Cloud.** See the session behind
> a bug or support ticket. Recordings remain in infrastructure you own.

“Unmetered” means Artisan Build does not meter sessions or recordings. The customer's own Cloud resource
limits and bill still apply, and Reel must make usage visible rather than imply infinite capacity.

## 2. Why this product exists

Two known clients each spend roughly $100/month on Contentsquare and primarily use it to browse recordings
around new features and errors. They do not use the broader analytics/API surface that helps justify a
digital-experience suite. Their narrow job is:

> Something went wrong, or we just released something unfamiliar. Show me what the user actually did.

The BfC advantage is not merely cheaper storage. It is the combination of:

- customer-owned recordings and retention;
- a Laravel-native installation and operational model;
- a deliberately narrow debugging/support workflow;
- direct correlation with ordinary Laravel requests and errors; and
- no mandatory Artisan Build data plane.

The experimental catalog state changes the appropriate proof strategy. Reel should be built to its intended
BfC shape, installed privately, and graduated only after internal and client use. A throwaway spike would
exercise nearly the same recorder, ingest, storage, privacy, player, and deployment work while creating a
second implementation to discard.

## 3. Users and jobs

### Primary users

- **Developer:** inspect the exact session around a 500, failed interaction, or regression.
- **Support/operator:** find a session by time, path, application user id, or reported problem and share an
  authenticated Reel link with an engineer.
- **Product owner:** spot-check a newly released workflow without buying a general analytics suite.
- **Application administrator:** configure capture, privacy, allowed origins, retention, and access.

### Core jobs

1. Find sessions that encountered a server error.
2. Find the session associated with a support report or application user id.
3. Replay a new or changed UI workflow.
4. Preserve a useful recording while a fix is in progress, then allow it to expire later.
5. Delete a session or all sessions associated with an application user id.
6. Confirm that recording, compaction, storage, and deletion are healthy.

## 4. Product goals and success conditions

### v0.1 goals

- Install a production-shaped Reel deployment through the BfC experimental catalog.
- Record and faithfully replay ordinary Laravel/Livewire browser sessions.
- Make privacy-safe capture the default and unsafe expansion explicit.
- Let Reel identify its own sessions containing fetch/XHR or Laravel-side errors.
- Export correlation through standard request headers and Laravel Context so the unmodified Nightwatch
  client carries it to Nightwatch SaaS or Hone.
- Retain ordinary sessions for 30 days and protected sessions until deliberately unprotected or deleted.
- Prove that the system is operable at realistic client volume and cost.

### Experimental graduation criteria

Reel may be marked active in the catalog only when all of the following are true:

1. A fresh deployment and client installation have been completed from the catalog instructions without
   repository surgery or undocumented operator steps.
2. Artisan Build has run it on its own application and the client whose Contentsquare question prompted Reel
   has run a consented pilot, across Chrome, Safari, and a mobile viewport between them.
3. At least one real 30-day expiry cycle has completed, including successful ordinary deletion, protected
   survival, unprotect-then-delete, and orphan cleanup.
4. The privacy attack fixture finds no default leakage of input values, contenteditable values, URL query
   strings/fragments, credentials, request bodies, console arguments, or blocked-page DOM.
5. Adversarial recorded markup cannot execute inside the Reel viewer or escape its replay sandbox.
6. Recorder overhead, compressed bytes/minute, ingest requests/session, queue behavior, and Cloud cost have
   been measured on the pilot applications and accepted in a written graduation note.
7. Reel being slow or unavailable does not break, delay materially, or prevent use of the monitored app.
8. Operators can diagnose rejected chunks, stuck compaction, storage growth, and failed retention jobs.
9. Artisan Build finds Reel useful in its own work and the pilot client confirms it solves the replay problem
   that prompted the inquiry, without either needing database or bucket access.
10. There are no unresolved severity-high security/privacy findings.

These criteria govern catalog visibility. They do not imply every deferred feature has shipped.

## 5. Built for Cloud contract and release state

Reel is an independent MIT-licensed BfC application deployed into the customer's own Laravel Cloud account.
Scalpels owns the catalog and orchestrates deployment but never receives recordings, user identifiers, replay
metadata, or application credentials.

The intended product model is first-party, customer-controlled debugging infrastructure: the application
owner deploys Reel for use by its own trusted organization, with recordings remaining in resources it owns.
Reel therefore does not inherently introduce an Artisan Build-operated recording recipient or shared SaaS
data plane. This is product positioning, not a universal legal classification. Infrastructure vendors and an
organization's own arrangements still matter, and the MIT license permits third parties to operate materially
different services that fall outside this intended-use claim.

### Experimental catalog contract

- Catalog state is an enum, not a pair of loosely related booleans: `experimental` or `active` initially.
- Experimental apps are absent from the public/front-page catalog but visible and installable inside the
  authenticated control plane.
- The UI labels Reel experimental before deployment and links to its known limitations.
- Experimental and active deployments use the same repository, manifest, upgrade path, and data model.
- Graduation changes catalog presentation, not the customer's deployment or stored data.
- Rolling back Reel to experimental must not disable existing deployments or updates.
- A public product page and marketing launch are graduation work, not prerequisites for dogfooding.

Scalpels is the authoritative owner of this state and its transition. Ballast has no Reel catalog role.

### Minimum Cloud manifest

- Laravel application compute;
- PostgreSQL database for searchable metadata and application state;
- Laravel Object Storage for private compressed replay objects;
- managed queue for compaction and deletion;
- scheduler for abandoned-session finalization, retention, and orphan cleanup;
- current BfC authority/auth package and its standard build/post-deploy steps; and
- no paid cache unless measurement proves it is required.

The manifest must be validated against Reel's actual configuration immediately before catalog insertion.

## 6. Scope

### v0.1 includes

- Web applications in desktop and mobile browsers; Laravel is the supported first-class host.
- rrweb-based recording and replay using an exact stable version selected and locked at build start behind a
  thin Reel adapter. A small temporary patch requires focused regression tests, documentation, and an upstream
  issue/PR or removal plan; a permanent behavioral fork blocks active graduation.
- Explicit consent/start integration; recording is off until the host application starts it.
- A Laravel client package containing a prebuilt browser recorder, configuration, session-token endpoint,
  correlation middleware, and install/update commands.
- Applications/projects, credentials, allowed origins, capture rules, and sampling settings.
- Compressed chunk ingest, idempotent ordering, asynchronous compaction, private object storage, and recovery
  of abandoned sessions.
- Session list/search, metadata/timeline, and playback with pause, seek, speed, and inactivity skipping.
- Error/custom markers and provider-neutral Laravel/Nightwatch correlation.
- Thirty-day retention, protect/unprotect, manual deletion, and deletion by application user id.
- Authenticated operator UI, invitations, admin authorization, and replay-view/delete audit records using
  the existing Built for Cloud auth conventions.
- Operational status sufficient to run a private production pilot.

### v0.1 excludes

- Heatmaps, funnels, journey analysis, experiments, surveys, and general analytics.
- Native mobile applications.
- Live co-browsing, remote control, chat, and WebRTC.
- Canvas, WebGL, video, audio, and cross-origin iframe fidelity guarantees.
- Request/response bodies, cookies, local-storage values, authorization headers, or console arguments.
- Network waterfall/profiling beyond sanitized status/error markers.
- Pixel video export or recording download as a video.
- AI analysis of raw replay data.
- An Artisan Build-hosted ingestion or storage service.
- A required Hone or Nightwatch dependency.
- First-class Hone UI changes, Nightwatch SaaS customization, and MCP tools. Standard correlation metadata
  ships in v0.1; provider-specific presentation can follow.
- Generic non-Laravel server integrations. The wire contract should not preclude them, but they are not a
  release requirement.

## 7. Installation and onboarding

### Deploy Reel

1. An authenticated catalog user selects experimental Reel and sees the warning/limitations.
2. The control plane forks/connects the Reel repository and provisions its declared Cloud resources.
3. Standard BfC bootstrap creates the first administrator without exposing a permanent bootstrap secret.
4. The administrator signs in to the new Reel instance and creates an application.

### Connect a Laravel application

Creating an application produces:

- a public application identifier;
- a short-lived, single-use enrollment code;
- the Reel ingest/base URL; and
- explicit allowed origins.

The host application then:

1. installs `artisan-build/reel-client` with Composer;
2. runs `php artisan reel:install`; the command generates an asymmetric keypair inside the monitored
   application, uses the enrollment code to register only the public key with Reel, and safely installs the
   Reel URL, application id, and private signing key as application secrets without editing unrelated values;
3. adds an explicit Blade component/directive to the application layout; and
4. calls `Reel.start()` only after the host's consent decision.

Applications explicitly exclude sensitive Laravel routes or route groups from capture:

```php
Route::get('/billing/payment-method', PaymentMethodController::class)
    ->hiddenFromReel();

Route::hiddenFromReel()->group(function () {
    require __DIR__.'/auth.php';
});
```

The package also registers a `reel.hidden` middleware alias for package/vendor route configuration where the
fluent macro cannot be applied directly.

The package ships a precompiled, self-hosted browser asset so a Laravel customer does not need to add a Node
build merely to use Reel. A future `@artisan-build/reel` package may support non-Laravel hosts from the same
source, but the Composer path is canonical for v0.1.

The package also exposes a URI builder for customer-service/admin interfaces, conceptually:

```php
Reel::sessionsUrlFor($user); // Uses the Eloquent model primary key, never name/email.
Reel::sessionsUrlForId($userId);
```

It returns an application-scoped authenticated Reel filter such as
`https://reel.example/applications/{application}/sessions?user_id={encoded-id}`. The URI is a convenient
filter, not a bearer/capability link: Reel login and application authorization are still required. Host apps
may place it beside their own user/customer record so support starts from the authoritative record and jumps
straight to that user's sessions.

The raw application-scoped id in this URI is an accepted workflow tradeoff. Reel strictly normalizes it as a
bounded scalar string, applies `Referrer-Policy: no-referrer` and `Cache-Control: no-store`, does not place it in
page titles or unrelated links, and runs no third-party analytics/resources on authenticated pages. Application
logs, exceptions, and structured telemetry redact query strings. The documentation states plainly that browser
history, screenshots, and customer-controlled infrastructure access logs may still retain the URI according to
the customer's own policies.

Uninstall instructions must stop recording before credential or server removal and must not require deleting
historical Reel data.

### Privacy readiness before real-user recording

Before Artisan Build dogfood or a client pilot records real users, the application owner performs and records
an application-specific walkthrough:

1. inventory representative routes, workflows, and sensitive data classes;
2. mark wholly sensitive routes/groups with `hiddenFromReel()` and sensitive components with mask/block rules;
3. choose `inputs` or `all_text` and document why ordinary rendered text is acceptable where retained;
4. record synthetic sessions containing recognizable sentinel secrets, then inspect both decoded outbound
   data and rendered playback;
5. record the effective capture-policy version/hash, reviewer, date, consent/disclosure approach, GPC decision,
   findings, and rationale in the pilot/graduation evidence; and
6. repeat the review before enabling materially different areas or data classes.

The monitored application owns its consent, disclosure, and lawful-processing decisions and calls
`Reel.start()` only after applying them. The client exposes `navigator.globalPrivacyControl` to that decision
and offers a one-line option to refuse recording whenever it is true, but does not silently reinterpret GPC's
do-not-sell-or-share signal as a universal prohibition on first-party debugging. The selected behavior is
visible in effective settings and must be documented for the pilot.

## 8. Recorder requirements

### Session identity and lifecycle

- After consent, request a recording grant from the Composer package's same-origin Laravel endpoint. The
  Laravel server—not browser JavaScript—generates the cryptographically random opaque session id and signs the
  authoritative metadata. Store the returned id and grant in `sessionStorage`, making the recording tab-scoped
  and persistent across reloads in that tab; the browser cannot substitute its own id.
- The issuer also keeps a bounded, expiring set of issued Reel session ids in the visitor's Laravel session so
  later application-request correlation can prove host issuance without sending the upload bearer grant.
- Start only through an explicit host call. Starting twice is idempotent.
- End on explicit stop, maximum duration/size, or server-side abandoned-session timeout.
- Flush periodically, at a compressed-size threshold, and on visibility/page-unload using keepalive/beacon
  where supported.
- Assign monotonic chunk sequence numbers and include recorder/envelope schema versions.
- Never let a recording exception propagate into or block the monitored application.
- Expose a small status/debug hook for the host, without writing captured data to the console.

Exact batch interval, maximum session duration, and compressed size caps are configurable operational
thresholds. Initial defaults must be set from fixture measurements before the first pilot, not guessed into
an irreversible protocol.

### Recorder non-interference and outage behavior

Reel instrumentation is observational and may never alter monitored application semantics:

- Install hooks once through a private singleton across repeated start, reload, and bfcache restoration.
- Preserve original `fetch`/XHR arguments, receiver, return value, promise resolution/rejection, response/body,
  events, and observable timing behavior; inspect status without consuming or replacing the response body.
- Contain every Reel exception. A broken interceptor disables/marks the recording with a sanitized diagnostic
  but cannot reject, delay, or change the host request.
- On stop, restore only hooks Reel still owns so a wrapper installed later by Livewire, Inertia, Sentry, or
  another tool is never overwritten.
- Enforce hard event/buffer memory and retry ceilings. When continuity can no longer be preserved, stop and
  mark the recording incomplete instead of dropping arbitrary prerequisite events or growing without bound.
- Use capped exponential backoff with jitter and a circuit breaker. Sustained outage stops upload attempts and
  exposes local/operational status without creating a retry storm.

### Capture defaults

- Mask all input/select/textarea values—including hidden values—and contenteditable text. This is the
  immutable minimum masking posture.
- Strip query strings and fragments from recorder page/navigation metadata before capture or transmission.
- Remove known Laravel ecosystem hydration payloads—including Inertia `data-page`, Livewire `wire:snapshot`,
  and legacy `wire:initial-data`—from initial snapshots and later attribute mutations before rrweb events enter
  the upload buffer.
- Remove navigational/resource URL values and CSS `url()` references before buffering or upload. Preserve
  element dimensions and sanitized inline CSS declarations so replay can render layout and placeholders
  without contacting the monitored application or another external origin.
- Never infer that route names, URL prefixes, middleware names, or starter-kit conventions reliably identify
  sensitive pages. Laravel applications explicitly mark routes and route groups with `->hiddenFromReel()` or
  the equivalent `reel.hidden` middleware.
- Block embedded data-URL/base64 images, canvas, video, and audio by default.
- Do not capture cookies, browser storage, request/response bodies, auth headers, or console arguments.
- Provide `data-reel-mask` and `data-reel-block` plus configured mask/block selectors and additional excluded
  paths.
- Path exclusions and masking apply before events leave the browser, not only during playback.
- Dynamically inserted nodes obey the same rules as the initial DOM snapshot.
- Cross-origin iframes are represented as unavailable rather than circumvented.

Masking configuration is **monotonic**: it may only increase protection beyond the mandatory baseline. Initial
severity modes are `inputs` (the default baseline) and `all_text`; teams may also add selectors and paths to
mask or block. No configuration, DOM attribute, application API, or admin action may expose mandatory-masked
input/contenteditable values, restore stripped page/navigation metadata, restore removed framework hydration
payloads, or re-enable a mandatory-blocked path. v0.1 has no
`data-reel-unmask`. If selective unmasking is considered later for teams using `all_text`, it may relax only
that team's added text policy and can never cross below the baseline.

The effective policy and its immutable baseline must be visible in the application settings UI. The privacy
fixture runs every severity mode and proves that stronger settings produce a subset of the baseline's visible
data, never additional data.

The client maintains a version-tested registry of known hydration attributes for the Laravel front-end stacks
Reel supports. These attributes contain bootstrap/component state that rrweb does not need to replay the
already-rendered DOM. Applications may add further attribute exclusions but cannot re-enable the registry.
Sanitization applies to full snapshots and incremental mutations; if it cannot safely sanitize an event, Reel
stops/discards that recording epoch rather than transmitting the unsanitized event. Tests inspect decoded
outbound batches, temporary chunks, and compacted objects—not only the rendered player.

This baseline does not assert that arbitrary DOM attributes are harmless. v0.1 deliberately favors a
network-free replay over external asset fidelity: navigation targets, image/media/iframe sources, stylesheet
locations, `srcset`, and CSS URL references are removed or replaced before transmission. Resource elements
retain safe geometry/placeholder information. Reel may later add bounded asset capture if pilot evidence
justifies the additional privacy, storage, and sanitization surface; it will not silently fall back to loading
the original URLs.

### Route-level capture exclusion

`hiddenFromReel` is absolute and monotonic: no nested route, configuration value, DOM attribute, or browser
API can re-enable capture for a marked request. The package registers the fluent API on Laravel's router,
route registrar, and concrete route so it composes with both group and individual-route syntax. Middleware is
the compatibility/source-of-truth mechanism; route metadata may additionally expose the decision on framework
versions that support it.

For an ordinary document response, the middleware ensures the recorder is never started, no upload token is
issued, and no Reel Context is added. For Livewire/Inertia/`wire:navigate`-style navigation, the response signals the
hidden policy before the framework applies the returned DOM: the recorder stops, detaches observers, and
discards unsent events that could include the hidden response. Returning to an allowed route begins a new
capture epoch with a sanitized full snapshot; hidden DOM is never used as the mutation base.

For a background request from an otherwise recordable page, the server suppresses Reel Context/server markers
for the marked request. If its response is capable of changing visible DOM, the browser integration must pause
before resolving that response to the host framework or document the framework-specific limitation. The macro
cannot protect sensitive content rendered inside an otherwise recordable route (for example a customer record
in a Livewire modal); teams use `data-reel-mask`, `data-reel-block`, configured selectors, or `all_text` for
those components.

The installer documents common categories worth reviewing—authentication, credential recovery, payment-card
entry, health data, and privileged administration—but Reel does not ship mutable path guesses as a security
boundary. A future read-only route audit may flag likely candidates for human review without automatically
changing capture.

### Sampling and identity

- Sampling is decided before recording begins and remains stable for that session.
- Support a percentage plus deterministic inclusion/exclusion by sanitized path.
- The Laravel package may attach the authenticated Eloquent model's primary key as an application-scoped,
  normalized string plus a release/deploy identifier. Non-Eloquent hosts must opt in with an explicit stable
  id resolver; Reel does not fall back to an authentication identifier that might be an email address.
- Reel stores that application user id directly on session metadata. It is an identifier/filter, not an
  authorization credential, and ids are never compared across Reel applications.
- Reel's identity integration does not attach or store the recorded user's name, email address, phone number,
  or other profile attributes, and it never scrapes DOM text to construct identity metadata. This is distinct
  from replay capture: under the input-only baseline, profile data rendered as ordinary page text may appear
  in the recording unless the team masks/blocks that element or enables `all_text`. The settings UI and install
  documentation must state this plainly.
- The UI supports exact search and deletion by application user id. The Laravel URI builder provides the
  preferred support workflow without requiring the operator to copy the id.

## 9. Ingest and storage requirements

### Upload authorization

- The long-lived signing private key never enters the browser or Reel storage.
- The locally generated public key is registered using a short-lived, single-use enrollment code. Reel never
  generates, displays, transmits, or persists the private key.
- The same-origin Laravel endpoint mints a standard, explicitly typed session-scoped JWT only after consent.
  It contains fixed issuer/audience and algorithm expectations plus application id, credential id, server-
  generated session id, monitored origin, protocol version, unique grant id, issue/expiry times, maximum event
  time, and signed resource ceilings. Use a vetted asymmetric implementation and the JWT BCP; do not design a
  cryptographic primitive or accept algorithm selection from the token.
- Reel derives authoritative metadata from the verified grant and rejects any conflicting application,
  origin, session, timing, or limit values supplied in an upload body.
- Reel validates signature, explicit type/algorithm, issuer, audience, age, application/credential state,
  allowed origin, session binding, and limits before doing queue or object-storage work.
- CORS and origin allowlists are browser controls, not proof against a non-browser attacker.

v0.1 has no refresh protocol. Grant expiry is the signed maximum recording duration plus a short delivery
grace; recording stops before the maximum event time. Longer activity starts a new recording. Periodic flush
is the durability mechanism and unload delivery is best effort, so an expired grant never receives a special
unload bypass.

### Browser upload transport

Use one bounded body envelope for ordinary and unload uploads. It carries the bearer grant, sequence metadata,
and compressed event chunk; the grant never appears in a URL. The unload form must be compatible with
`sendBeacon`/keepalive without a custom authorization header or mandatory preflight and stay well below limits
measured in the supported browser matrix. Hard compressed and streaming decompressed-size ceilings apply at
the earliest practical layer. A failed final beacon may lose only the tail since periodic uploads are primary.

### Abuse and integrity controls

- Hard payload and decompressed-size limits.
- Same-origin/CSRF checks and issuance limits by host session and IP reduce cross-site and automated grant
  vending; they are controls, not a claim that anonymous callers can be authenticated.
- Fail-closed per-application and per-session limits cover new sessions, concurrent sessions, requests,
  chunks, bytes, and daily usage before expensive queue/object work. Each application has an emergency kill
  switch for issuance and ingest.
- Recorders assign monotonic sequences within explicit writer/navigation epochs; ingest accepts bounded out-
  of-order arrival. `(application_id, session_id, epoch_id, sequence)` plus checksum identifies a chunk: an
  exact retry is idempotent and the same identity with different bytes is rejected.
- Bounded session lifetime and bytes.
- Cheap rejection before database, queue, or object writes where practical.
- Rotation/revocation for application signing credentials without deleting recorded data; multiple public
  verification keys may overlap briefly for safe rotation.
- Unknown protocol versions fail clearly and do not create half-valid sessions.

### Persistence

- PostgreSQL stores users, applications, credentials, session metadata, chunk manifests, markers, retention
  state, and audit events—not rrweb event blobs.
- Private Object Storage holds compressed temporary chunks and compacted replay objects.
- A queue job validates ordering and compacts a finalized v0.1 session into one replay object, then removes
  temporary chunks idempotently. The manifest remains capable of listing multiple ordered objects so measured
  segmentation can be added without changing the envelope or session model.
- A manifest records envelope version, rrweb version, compression, object keys, checksums, event/time bounds,
  and compaction state.
- Playback objects are served only through authenticated/authorized Reel requests or short-lived signed
  delivery URLs; the bucket is never public.
- A conservative orphan sweeper removes objects that have no live database reference after a safety delay.

Start with app-mediated ingest. Direct browser-to-bucket uploads are deferred until measurement proves app
bandwidth/compute is the limiting cost; they complicate abuse rejection, ordering, and cleanup.

### Session and object state machine

The authoritative database transition is:

`recording → closing → compacting → ready → deleting → deleted audit tombstone`

`recording`, `closing`, or `compacting` may instead become `failed`; `failed` may only proceed to `deleting`.
Protection is available only for `ready` sessions. Once `deleting` wins the row lock, it is terminal for
uploads, protection, compaction publication, and replay delivery.

- Each document/reload/SPA recording epoch starts with a full snapshot and its own writer id/sequence space.
  Recorder installation is idempotent across bfcache restoration. Concurrent writers are detected and made
  visible rather than silently merged as one trustworthy stream.
- Explicit close declares the last sequence for each known epoch when possible. Abandoned sessions enter
  `closing` after bounded inactivity, wait through a late-arrival window, and remain marked incomplete when a
  terminal sequence is missing or gaps remain.
- `closing` accepts only bounded late/gap-filling chunks until its cutoff. `compacting` rejects uploads.
- A compactor streams chunks in epoch/sequence order to a uniquely named candidate, verifies size/checksum and
  manifest, then atomically publishes the manifest and `ready` state in PostgreSQL. Only after publication may
  temporary chunks be removed. A duplicate job observes the published manifest and becomes a no-op.
- Before publication, the compactor rechecks that the locked session is still `compacting`. A concurrent
  transition to `deleting` prevents publication and schedules candidate cleanup.
- All objects live below an application/session prefix. Deletion revokes ingest/delivery, removes temporary,
  candidate, and published objects under that exact prefix idempotently, then retains only the minimum audit
  tombstone after absence is verified.
- Failed, abandoned, and never-finalized sessions receive a `started_at`-based maximum expiry so absence of
  `ended_at` cannot evade retention.
- After a database restore or other reconciliation uncertainty, the orphan sweeper suspends destructive work
  and reports unhealthy until an explicit database/object high-water reconciliation is completed.

## 10. Session metadata, search, and playback

### Session list

Show and filter by:

- start/end time and duration;
- application;
- sanitized initial/latest path;
- opaque session id;
- optional application user id;
- release/deploy identifier;
- status: recording, processing, ready, failed, expired/deleting;
- error/custom marker type;
- protected/unprotected; and
- watched/unwatched by the current operator.

Default order is newest first. Filters compose, are reflected in the URL, and remain bounded/indexed at pilot
volume. The list never downloads replay blobs.

### Session detail and player

- Display metadata and a timeline before downloading the replay.
- Player supports play/pause, seeking, speed, inactivity skipping, and timestamped marker navigation.
- A direct authenticated URL opens a session and optional timestamp.
- If an object is missing/corrupt/incompatible, show a diagnostic state rather than a blank player.
- Record each replay view in an audit log with viewer, time, application, and session—not captured DOM data.
- Captured DOM is hostile input. It must not run scripts, event handlers, navigation, forms, downloads,
  popups, or network requests from the viewer.
- Reconstruct it inside an opaque-origin sandbox that omits `allow-same-origin`, forms, navigation, popups,
  and downloads. A restrictive CSP starts with `default-src 'none'`; only a nonce/hash-authorized player
  runtime and sanitized inline styles may be permitted. Captured scripts and inline event handlers never run.
- Set `Referrer-Policy: no-referrer`. Communication with controls outside the sandbox uses a narrow,
  schema-validated `postMessage` channel bound to the expected window and per-player nonce.
- The player must remain isolated from Reel's authenticated application shell, cookies, storage, and secrets.
  Network-canary tests—not the mere presence of an iframe or sandbox attribute—prove the boundary.

## 11. Error and telemetry correlation

Reel owns correlation; observability providers consume it optionally.

### Reel-native error markers

- The browser adapter observes same-origin fetch/XHR responses with status `>= 500` and adds a local marker
  containing time, sanitized URL/path, method, and status only.
- The Laravel package can add a sanitized server marker for handled request failures/exceptions when an exact
  Reel session id is available.
- Marker rows may contain session id, time, route/status, deploy id, trace/execution id when available, and a
  sanitized exception/group key. They never contain request bodies, exception messages containing user data,
  stack locals, or DOM content.
- Reel's “sessions with errors” filter works when neither Nightwatch nor Hone is installed.

### Standard export to Nightwatch or Hone

- Add `X-Reel-Session` to same-origin fetch/XHR/Livewire requests.
- Treat the header as untrusted browser input. Middleware accepts it only when it matches an unexpired id in
  that visitor's bounded Laravel-session issuance set; format validation alone is insufficient. The upload
  bearer grant is never attached to monitored-application requests.
- Always redact `X-Reel-Session` from Nightwatch request-header capture and verify the emitted payload. Reel's
  deliberate Laravel Context is the only telemetry export carrier.
- Export is explicit configuration with `off`, `session_id`, and `session_id_and_url` modes. Unattended install
  defaults to `off`; interactive install explains that enabled metadata goes to whatever Nightwatch transport
  the application configured, which may be Nightwatch SaaS, Hone, or another endpoint.
- When enabled, middleware adds only host-bound values under one structured `reel` Context key. Include
  `binding: host_bound` to mean the monitored Laravel application issued the id—not that Reel has already
  ingested or successfully finalized a replay. A URL is present only in `session_id_and_url` mode.
- Do not modify or fork the Nightwatch client. Its ordinary request payload carries Laravel Context to
  Nightwatch SaaS or a Hone endpoint, and its exception occurrence remains linked to the request.
- Add and clear request-local Context in all success/exception paths; long-lived Octane workers must not leak
  one request's Reel values into another.
- Hone may later index/render a first-class Reel link, but Reel v0.1 does not require a Hone change.
- Nightwatch issue webhooks are not used for exact correlation because their public contract is issue-group
  lifecycle, not occurrence-level request Context.

### Precision limitations

- Fetch/XHR/Livewire and instrumented form submissions can be exact.
- Browser JavaScript cannot normally add an arbitrary header to a conventional top-level GET.
- v0.1 uses the monitored application's bounded Laravel-session issuance/activity set as an explicitly
  approximate fallback. If exactly one recent active Reel session exists, attach it with an `approximate`
  binding. If several tabs are plausible, do not silently choose one: link/filter to the bounded time/path/user
  candidates and show the ambiguity. v0.1 does not install a service worker or navigation-handoff mechanism.
- Do not put a durable Reel session identifier into public application URLs by default.
- Multiple simultaneously active tabs must never be silently presented as one exact recording.
- Nightwatch SaaS is conservatively guaranteed to receive copyable metadata; whether arbitrary Context URLs
  are clickable/searchable is a graduation-spike question outside Reel's control.

## 12. Retention, protection, and deletion

### Default policy

- `expires_at = ended_at + 30 days` and initial `delete_not_before = expires_at` for ordinary sessions.
- An authenticated Reel user may protect a ready session before deletion begins.
- Protecting records `protected_at`, `protected_by`, and an audit event. The first protection establishes its
  owner; protecting an already-protected session is a no-op and cannot replace `protected_by`.
- Protected sessions remain until explicitly unprotected or manually deleted.
- Only the user recorded in `protected_by` or an administrator may unprotect a session. If that user is deleted
  or anonymized, only an administrator can unprotect it.
- Unprotecting sets `delete_not_before = max(expires_at, unprotected_at + 72 hours)`. The UI warns before the
  action, then displays the actor and scheduled deletion time. Any authenticated user may re-protect during
  cooling; that action becomes the new protection and owner. An administrator may still explicitly delete now.
- Every protect/unprotect event records actor and timestamp and the session UI displays that user's information.
  Deleted/anonymized users remain legible as such without silently erasing the historical action.
- Administrators can delete any session immediately; ordinary authenticated viewers cannot delete sessions.
- Administrators can delete all sessions for an application user id and receive an auditable result. This
  erasure overrides protection/cooling only after explicit confirmation.
- A user-id deletion audit retains actor, application, time, deleted counts/outcome, and an opaque deletion-
  batch id—not the erased user id. Active jobs are revoked and all matching temporary, candidate, and compacted
  objects participate in the same idempotent deletion workflow.

### Deletion mechanics

- Reel's database-aware scheduler selects only unprotected sessions whose `delete_not_before` has passed.
- Row locking/state transitions prevent protect/delete races.
- Object and metadata deletion is retryable and idempotent; partial failure remains visible until reconciled.
- Metadata is removed or reduced to the minimum deletion audit only after referenced objects are gone.
- No unconditional 30-day bucket lifecycle may delete protected recordings.
- The UI shows protected count and estimated storage so indefinite retention cannot grow invisibly.

## 13. Authentication and authorization

Reuse the current Built for Cloud auth/authority conventions rather than inventing Reel-specific identity:

- the application owns its `User` model and Fortify/login UI;
- BfC augments it with invitations and the admin flag/authorization middleware;
- standard ownership/claim and token authority endpoints remain available to the control plane; and
- no login, invitation, signing private key, or upload token is written to logs.

Initial permissions:

| Capability | Authenticated viewer | Administrator |
|---|---:|---:|
| List and replay sessions | Yes | Yes |
| Protect a session | Yes | Yes |
| Unprotect own protection | Yes | Yes |
| Unprotect another user's protection | No | Yes |
| Configure applications/capture | No | Yes |
| Rotate/revoke application credentials | No | Yes |
| Delete sessions/user history | No | Yes |
| Invite/manage users | No | Yes |
| View ingest/retention diagnostics | Read-only | Full |

The Reel deployment is the trust and data-segregation boundary. Every authenticated user can list and replay
every monitored application connected to that deployment; v0.1 has no user↔application memberships. Multiple
applications may share a deployment only when they share the same trusted operator cohort. An organization
that needs separation by application, team, environment, client, or data classification provisions separate
BfC Reel installations, each with independent authentication, database, object storage, credentials, audit
history, and retention controls.

Application scoping remains mandatory for integrity: credentials, session ids, recorded-user ids, object keys,
queries, and mutations must resolve within their owning application and cannot be confused or substituted
across applications. That is not a per-viewer authorization boundary. Do not rely on obscurity of UUIDs or
object keys.

## 14. Operational requirements

- Dashboard health for recent ingest, rejection reasons, sessions awaiting compaction, queue lag, failed jobs,
  storage estimate, oldest undeleted expiry, and last successful retention sweep.
- Structured logs contain operational/session ids and statuses but no recorded-user name/email, captured DOM,
  credentials, or upload tokens. The documented authenticated filter URI contains the application user id;
  deployments should apply their ordinary access-log retention policy accordingly.
- Queue and scheduler jobs are idempotent and safe under overlapping execution.
- Backpressure rejects or samples new recording work predictably before exhausting the monitored deployment.
- Database loss must not make the private bucket publicly enumerable; orphan recovery is documented.
- Upgrade order is server before recorder when a protocol change requires it. Readers tolerate older supported
  envelope versions; migrations are additive through the experimental period where practical.
- Backup/restore documentation states that database and bucket must be restored consistently.
- User-id deletion is described as deletion from the live Reel deployment, not immediate absolute erasure from
  immutable backups. Backup copies follow the customer's retention policy; restoring an older backup requires
  reapplying applicable deletion requests before normal access/ingest resumes.
- Install, upgrade, credential rotation, incident disable, export limitations, and uninstall runbooks ship
  before the first client pilot.
- A single application kill switch stops issuance/acceptance of new sessions without deleting existing data.
- Compatibility CI uses PostgreSQL for lock/race behavior; automated Chromium and WebKit lifecycle suites;
  Livewire, Inertia, and competing fetch/XHR wrapper fixtures; parallel queue/fault-injection schedules; golden
  recordings for supported rrweb/envelope versions; and a fresh-Cloud smoke covering private Object Storage,
  signed delivery, queue, and scheduler. Real Safari/iOS smoke is required before client pilot and graduation.
- Dogfood establishes explicit pre-pilot limits for main-thread/page regression, recorder memory/buffer bytes,
  upload bytes per minute/session, outage retries, compaction CPU/memory/duration/queue lag, player startup, and
  projected monthly Cloud cost. Hard safety caps stop safely; missed graduation budgets keep Reel experimental.

### State-machine observability and post-launch analysis

Every transition records previous/new state, reason, attempt, and timestamp without recording DOM or tokens.
The dashboard and structured metrics must make these likely failure signatures directly identifiable:

| Signal | Likely failure it identifies |
|---|---|
| Gap count, reorder distance, incomplete-close rate | Lost beacon/chunk, excessive late arrival, or an invalid ordering assumption |
| Conflicting retry count | Duplicate writers, sequence reuse, corrupted retry, or hostile upload |
| Concurrent writer/epoch count | Duplicated tab, bfcache/double-install bug, or unintended session sharing |
| Sessions over the state-age threshold | Stuck close, lost queue dispatch, wedged compactor, or stuck deletion |
| Late-upload rejection by current state | Recording beyond grant/cutoff or compaction beginning too early |
| Compaction attempts, duration, peak memory, and no-op duplicates | Queue redelivery, visibility-timeout mismatch, oversized jobs, or non-streaming work |
| Candidate/manifest checksum failures | Partial object write, corrupt chunk, incompatible envelope, or compactor defect |
| Manifest-without-object and object-without-live-manifest counts | Database/object divergence, failed publication cleanup, or restore mismatch |
| Deleting age, retries, and remaining prefix objects | Partial object-store failure or deletion/compaction race |
| Oldest overdue unprotected expiry | Scheduler failure, invalid retention query, or undeletable session |
| Post-delete publish/write prevention count | In-flight writer or compactor correctly caught after deletion; any successful write is critical |
| Orphan-sweeper suspended/high-water status | Restore uncertainty where automatic deletion must remain disabled |

Session diagnostics expose state history, epoch/chunk ranges, checksums, object/manifest presence, job attempts,
and sanitized failure codes to administrators. Repair/reconcile commands are dry-run by default, idempotent,
prefix-scoped, and documented before the client pilot.

Post-launch analysis is required, not optional polish. Establish the dashboard baseline before Artisan Build
dogfood; review it daily during the first dogfood week, weekly during the client pilot, and again after one full
30-day retention window before active graduation. The review covers ordering/gap distributions, incomplete and
abandoned rates, duplicate writers, state-age outliers, compaction resource envelopes, manifest/object drift,
deletion convergence, overdue retention, and every prevented post-delete write. Tune operational thresholds
from these observations, document every unexplained failure and repair, and block graduation while destructive
state divergence or unexplained data loss remains.

## 15. Acceptance criteria for v0.1

### Deployment and onboarding

- Experimental Reel is absent from the public/front page and installable from the authenticated catalog.
- Its catalog manifest provisions exactly the resources in §5 and a fresh deploy becomes healthy.
- First-admin bootstrap, application creation, single-use enrollment, host-local key generation/public-key
  registration, installation, rotation, and disable are covered by end-to-end tests or an automated smoke run.
- The Laravel helper builds an authenticated application-scoped session-filter URI from an Eloquent model or
  explicit user id without including name/email or granting access by possession of the URI.
- User-id filter pages prove no-store/no-referrer behavior, bounded scalar normalization, query redaction from
  Reel logs/errors, no unrelated-link propagation, and no third-party authenticated-page requests.
- A real-user application cannot pass pilot readiness until its route/workflow inventory, effective masking
  policy hash, synthetic sentinel test, consent/disclosure approach, GPC decision, reviewer/date, and rationale
  are recorded; enabling materially different data classes requires a new review.

### Recording and replay

- A consented Laravel/Livewire fixture records navigation, DOM changes, clicks, scrolling, form interaction,
  validation, and modal behavior and replays them in chronological order.
- Refreshes within one tab retain the session identity; concurrent tabs are distinct.
- Duplicate/out-of-order chunks do not duplicate or corrupt a replay.
- Abandoned and gapped sessions finalize as visibly incomplete; incompatible/corrupt sessions produce
  diagnostics. Reload, bfcache, duplicate-writer, late-upload, duplicate-job, compaction/delete race, partial
  object write, restore uncertainty, and stuck-state fixtures prove the transition invariants in §9.
- v0.1 compacts to one object and its manifest reader also accepts an ordered multi-object fixture. Measured
  worst-supported dogfood sessions must fit compactor memory/duration and player startup/seek budgets; only a
  failure of those budgets authorizes production segmentation work.
- Reel failure never makes the fixture's application request or UI action fail.
- Throwing instrumentation, repeated install/stop, bfcache, competing wrapper order, consumed-body traps, and
  prolonged ingest outage prove byte-for-byte/request-semantic equivalence with Reel disabled. Buffer ceilings
  stop visibly incomplete and capped jitter/circuit-breaker behavior produces no retry storm.

### Privacy and security

- Default fixture assertions cover every forbidden data class in §8.
- All capture exclusions occur before transmission.
- Inertia and Livewire fixtures prove that named hydration payloads are absent from decoded outbound batches,
  temporary chunks, and compacted replay objects in both full snapshots and incremental mutations.
- Decoded recordings contain no navigation/resource URL values or CSS URL references. Images, media, iframes,
  and unavailable resources replay as dimension-preserving placeholders while sanitized inline CSS preserves
  useful layout.
- Individual and grouped `hiddenFromReel` routes never start recording or issue Reel Context/tokens. Browser
  tests prove allowed→hidden→allowed navigation discards hidden events and resumes with a fresh epoch across
  ordinary navigation plus supported Livewire/Inertia navigation.
- Application A credentials and identifiers cannot upload, resolve, deliver, or mutate application B data.
  Authenticated viewers can intentionally select every application in their Reel deployment; an end-to-end
  test proves a second Reel installation has completely separate users, database records, objects, and keys.
- Malicious recorded HTML cannot execute, access Reel authority, or exfiltrate from playback. Browser tests
  place canary endpoints in HTML attributes, `srcset`, SVG, CSS, fonts, forms, and navigation and prove zero
  requests under normal play, seek, and error handling.
- Upload token expiry, tampering, wrong origin/application/session, replay, decompression bomb, oversize chunk,
  and rate-limit cases fail closed before expensive work.
- A browser-chosen session id or conflicting body metadata is rejected. Tests cover grant exhaustion, daily
  application ceilings, ordinary periodic upload, successful bounded beacon upload, and expected final-tail
  loss when beacon delivery fails or the grant is expired.
- Secrets/tokens/captured DOM are absent from logs and exception reporting.

### Correlation

- A fetch/XHR 500 creates a Reel marker at the correct playback timestamp.
- A Laravel exception with a session header results in structured Reel Context in an unmodified Nightwatch
  request payload only when the id is bound to the visitor's Laravel session and export is enabled; it remains
  associated with the parent request trace and is labeled `host_bound` rather than Reel-confirmed.
- The same fixture passes when Nightwatch transport targets Hone and when it targets the stock SaaS endpoint
  contract; Reel itself remains functional with Nightwatch disabled.
- Payload fixtures prove the raw `X-Reel-Session` header and upload grant are absent in every mode, `off` emits
  no Reel Context, and the two enabled modes emit only their documented fields. Octane sequential/concurrent
  request tests prove Context cleanup.
- Top-level navigation behavior is tested and described honestly as exact or approximate.
- Top-level GET tests prove a sole recent Laravel-session candidate is labeled approximate and multiple-tab
  candidates produce an ambiguity/filter result rather than a falsely exact link; no service worker is installed.

### Retention

- Expired ordinary sessions are deleted; protected sessions survive the same sweep.
- Protect racing with deletion has one deterministic safe result and cannot leave an untracked object.
- Only the protecting user or an administrator can unprotect. Unprotecting at or near expiry waits at least 72
  hours, advertises the scheduled deletion, and remains reversible through re-protection; admin deletion and
  explicitly confirmed user-id erasure may proceed immediately and remove all referenced objects idempotently.
- Object-store errors remain retryable and visible without falsely marking deletion complete.
- An in-flight or duplicate compactor cannot publish after `deleting`; prefix reconciliation finds candidate,
  temporary, published, missing, and extra objects without enabling the orphan sweeper after restore uncertainty.
- User-id deletion removes matching live metadata, jobs, and every object form, while its durable audit omits
  the erased id. Backup/restore tests and runbooks make the live-deletion boundary and required reapplication
  of deletion requests explicit.

## 16. Proposed build sequence

Build as one experimental product, but keep PRs independently reviewable and mergeable:

1. **BfC application foundation:** repository, current BfC authority/auth, CI/static analysis, Cloud manifest,
   experimental catalog entry, admin bootstrap, application/credential model.
2. **Recorder contract and Laravel client:** versioned envelope, prebuilt rrweb adapter, consent API, masking,
   session token, install/update commands, hostile privacy fixture.
3. **Ingest and persistence:** authorization/limits/idempotency, chunk objects, metadata, queue compaction,
   abandoned-session finalization, operational counters.
4. **Replay workflow:** indexed session list, filters, metadata/timeline, sandboxed player, corruption states,
   view audit.
5. **Correlation:** browser error markers, Laravel lifecycle markers, request header/Context export, stock
   Nightwatch/Hone contract fixtures, precision labeling.
6. **Retention and administration:** 30-day expiry, protect/unprotect, deletion/user erasure, orphan sweeper,
   credentials/capture settings, diagnostics.
7. **Dogfood hardening:** browser/privacy/security/performance matrices, Cloud cost measurement, runbooks,
   internal deployment, consented client pilot, and graduation report.

No PR should introduce a parallel throwaway ingest/player path. If an uncertain component needs isolation, put
it behind the intended production interface and either harden or replace that component without rebuilding the
whole application.

## 17. Decisions

### Resolved

1. **Catalog ownership:** Scalpels owns the experimental/active BfC catalog state. Ballast was named in error.
2. **Masking:** input/contenteditable masking is the mandatory minimum. Teams can only strengthen it through
   configuration; `all_text`, additional selectors, and additional blocked paths ship in v0.1. There is no
   baseline-weakening setting or unmask attribute.
3. **Protection:** any authenticated user may protect a ready recording, which establishes that user as the
   protection owner. Only that owner or an administrator may unprotect it; every action remains attributed in
   the audit/UI. Unprotecting sets deletion no earlier than both normal expiry and 72 hours after the action,
   and any user may re-protect during cooling. Immediate deletion and confirmed user-id erasure remain admin-
   only; once `deleting` begins, protection loses deterministically.
4. **Promotion evidence:** dogfood at Artisan Build and pilot with the client who prompted the Contentsquare
   inquiry. Promote to active only if Reel is useful internally, solves that client's problem, and passes the
   technical/privacy graduation gates. Otherwise leave it experimental while evidence accumulates.
5. **Recorded-user lookup:** store only the application-scoped primary user id—no email/name/profile data.
   Customer service starts from the user record in the monitored Laravel app and follows a URI produced by the
   Reel client package to an authenticated `user_id`-filtered session list. The id is a filter, never access.
6. **Sensitive routes:** Reel cannot infer sensitive pages from Laravel URLs, starter kits, or route names.
   The Laravel package supplies absolute `->hiddenFromReel()` route/route-group macros backed by middleware;
   universal non-configurable safety remains data-class masking. Component-level sensitive content on an
   otherwise recordable route requires selectors, DOM block/mask attributes, or `all_text`.
7. **Framework hydration attributes:** before buffering or upload, remove a version-tested, non-configurable
   registry of known Laravel ecosystem state payloads, initially Inertia `data-page`, Livewire `wire:snapshot`,
   and legacy `wire:initial-data`. Verify decoded data at every persistence stage. Broad URL/resource-attribute
   treatment remains part of the replay asset/isolation decision rather than a guessed generic sanitizer.
8. **Session grants and upload transport:** the monitored Laravel server generates session ids and signs
   explicitly typed, audience-bound JWT upload grants using a private key generated locally during one-time
   enrollment; Reel stores only the public key. v0.1 grants last for the bounded recording duration plus
   delivery grace and are not refreshed. Periodic bounded body uploads are primary; the same body-carried grant
   supports best-effort beacon/keepalive flushes without URL credentials. Anonymous abuse is bounded through
   issuance throttles and hard per-session/application/daily ceilings, not described as preventable.
9. **Replay isolation and assets:** v0.1 playback is network-free. The recorder removes navigation/resource
   URLs and CSS URL references before transmission and preserves safe layout/placeholders rather than loading,
   proxying, or capturing external assets. Hostile events render in an opaque-origin, no-`allow-same-origin`
   sandbox under a default-deny CSP and no-referrer policy; only a tightly authorized player runtime,
   sanitized inline styles, and a nonce/schema-bound message channel are permitted. Network-canary browser
   tests are the acceptance boundary. Bounded asset capture may be reconsidered only after pilot evidence.
10. **Application access boundary:** the Reel installation—not a monitored-application membership—is the
    authorization and data-segregation boundary. All authenticated users can view all applications connected
    to their installation. Applications sharing an operator cohort may coexist; any organization needing
    separation provisions independent BfC Reel installations with separate auth, database, object storage,
    credentials, audit, and retention. Application scoping still prevents credential/id/object confusion but
    is an integrity rule, not per-viewer authorization. v0.1 has no user↔application membership model.
11. **Session/object lifecycle:** implement the explicit `recording → closing → compacting → ready → deleting`
    state machine and failed/deleted branches before storage work. Accept bounded out-of-order chunks within
    explicit epochs, publish verified manifests before chunk cleanup, make deletion terminal, and fail closed
    after restore uncertainty. Instrument state ages, gaps, conflicting retries, writer overlap, compaction,
    object divergence, deletion convergence, retention lag, and prevented post-delete writes. Daily dogfood,
    weekly pilot, and full 30-day retention-window reviews are required evidence for active graduation.
12. **Intended trust and privacy-review model:** Reel is positioned as first-party, customer-controlled
    debugging infrastructure for the application owner's trusted organization, with no Artisan Build recording
    data plane; MIT permits other operators to create differently classified services. Real-user dogfood/pilot
    requires an application-specific route/data walkthrough, effective-policy record, synthetic sentinel test,
    and documented consent/disclosure/GPC rationale. The host owns the start decision. Reel exposes GPC and an
    easy refuse-on-GPC option but does not treat its do-not-sell/share meaning as a universal recording ban.
13. **Raw user-id URI and erasure:** retain the simple application-scoped raw-id filter URI as an accepted
    support workflow, protected by authentication, bounded normalization, no-store/no-referrer responses, no
    third-party authenticated-page resources, and query redaction from Reel logs/errors; document residual
    browser-history/screenshot/infrastructure-log exposure. User-id deletion removes live data/jobs/objects but
    its audit retains only actor, app, time, counts/outcome, and opaque batch id. Backups follow customer policy;
    Reel claims live-deployment deletion and requires reapplying requests after an older restore, not immediate
    absolute backup erasure.
14. **Nightwatch/Hone export:** bind a claimed browser header to a bounded, expiring set of ids issued into that
    visitor's Laravel session; never send the upload grant on application requests. Always redact the raw header
    from Nightwatch. Explicit `off`, `session_id`, and `session_id_and_url` Laravel Context modes default off in
    unattended installs and disclose the configured observability destination. Exported correlation is labeled
    `host_bound`, not Reel-confirmed; middleware clears Context for Octane safety. Pinned outbound-payload tests
    cover stock Nightwatch and Hone without modifying either client and promise copyable—not necessarily
    clickable/searchable—metadata.
15. **Failure isolation and executable gates:** recorder hooks are idempotent, ownership-aware, body-preserving,
    exception-contained, and semantically transparent to fetch/XHR and competing wrappers. Hard buffer/retry
    ceilings stop a recording as incomplete; capped jitter and a circuit breaker prevent outage storms. Real
    PostgreSQL, Chromium/WebKit plus Safari/iOS smoke, lifecycle/integration wrappers, parallel fault injection,
    golden version recordings, and fresh-Cloud resource tests are required. Dogfood sets explicit performance,
    resource, playback, and monthly-cost budgets before client traffic; safety-cap breaches stop recording and
    budget misses block active graduation rather than being guessed into the protocol now.
16. **Top-level navigation correlation:** v0.1 accepts an honest approximation from the bounded set of Reel ids
    issued/active in the same Laravel session. One recent candidate is labeled `approximate`; multiple plausible
    tabs produce a time/path/user candidate filter and visible ambiguity rather than a selected exact replay.
    Do not install a service worker or navigation handoff in v0.1.
17. **Storage format:** compact each v0.1 session into one replay object behind a manifest that can already list
    ordered objects. Add segmentation only if measured worst-supported dogfood sessions miss compactor memory/
    duration or player startup/seek budgets, without changing the envelope/session model.
18. **rrweb version policy:** select and exactly lock a stable rrweb release at build start behind Reel's thin
    adapter. A small temporary patch requires focused regression tests, documentation, and an upstream issue/PR
    or removal plan. A permanent behavioral fork blocks active graduation.

### Implementation measurements still required

The product decisions are complete. Exact buffer, batch, duration, gap, grace, performance, segmentation, and
cost thresholds remain measurement outputs governed by the dogfood/pilot gates above; they are not reasons to
reopen the protocol or silently weaken a safety invariant.

## 18. Explicit kill/reshape conditions

Pause graduation—not necessarily the experiment—if:

- privacy-safe masking makes the replay useless for the target workflows;
- an important client depends on canvas/cross-origin/native behavior outside the product boundary;
- safe replay of hostile captured DOM requires a permanent rrweb fork or unacceptable viewer isolation;
- monitored-app overhead or ingest cost is material at representative volume;
- customers actually depend on Contentsquare's analytics/organizational workflow rather than replay;
- Laravel installation cannot be made substantially simpler than a generic self-hosted competitor;
- exact error correlation requires modifying Nightwatch or operating an Artisan Build data plane; or
- protected-session retention cannot be made race-safe and operationally visible.

If the bounded core passes and a deferred analytics feature is requested, preserve Reel's debugging product
boundary until repeated customer evidence—not competitive checklist pressure—justifies expanding it.
