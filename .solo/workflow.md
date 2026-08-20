# Workflow — reel

Project profile for the `multi-agent-build` skill. The coordinator agent reads this FIRST.

Reel is customer-owned browser session replay for debugging Laravel applications, shipped as an
experimental Built for Cloud product on Laravel Cloud. Public repository, MIT licensed. As of this
profile the repository is the application scaffold and its quality gate only — no product features.

## Phase & mode
- phase: pre-launch
- default mode: A-autonomous
- merge_policy: the coordinator merges autonomously, and only after ALL of:
  1. the hard gate below is green on a clean, committed SHA;
  2. an independent quality review and an independent acceptance judge both pass (see harness map —
     different model lineages from the implementer); and
  3. GitHub Actions CI is green on that SHA.
  No human PR code review.
- merge method: `gh pr merge --squash`

## Hard gate (must be green before review; coordinator verifies on the committed SHA, clean tree)
- command: `composer ready`
- what it runs, in order: `ide-helper` (generate + models + meta) → `rector process` → `pint --parallel`
  → `phpstan analyse --memory-limit=512M` (Larastan, level 6, empty baseline) → `composer test`
  (`config:clear` + `pint --parallel --test` + `php artisan test`, Pest 4) → `composer audit`.
- `composer ready` REWRITES files (ide-helper, Rector, Pint). Run it, commit whatever it changed as
  the ride-along commit below, and only then verify the gate on a clean tree — a dirty tree after
  `ready` is not a pass.
- extra suites: none. There are no path packages yet (see monorepo).
- monorepo: no — a single Laravel application, no `packages/` and no path repositories.
  PRD §16 step 2 introduces `artisan-build/reel-client`. Whether that package lives here as a path
  package with a `kibble:split` release (the hone/matte convention) or in its own repository is an
  OPEN decision for that PR. Whoever resolves it updates this field, adds the per-package suites to
  the gate, and adds the package job to CI.
- requires a running PostgreSQL server. The suite uses a real `reel_app_test` database
  (`phpunit.xml`), not SQLite.
- `composer audit` is network-dependent and blocking: a newly published advisory can fail the gate
  on a branch that changed nothing related. That is intended; treat it as real work, not flake.

## CI (the merge gate for Mode A)
- status: verified — testing (Pest) + static analysis (PHPStan/Larastan) both present and enforced,
  which meets the Mode A bar. Verified green on the bootstrap commit.
- workflows/jobs:
  - `.github/workflows/tests.yml` — `ci` job, matrix PHP 8.4 and 8.5, PostgreSQL 16 service
    (`reel_app_test`): `composer stan` → `./vendor/bin/pest` → `composer audit`.
  - `.github/workflows/lint.yml` — `quality` job, PHP 8.5: `composer lint:check` (`pint --test`).
    Check-only on purpose: a CI run of `composer lint` rewrites files nothing commits, so it can
    never fail and is not a gate.
- both trigger on push and pull_request against `main`.
- CI needs NO repository or organization secrets. `livewire/flux` resolves free from Packagist —
  verified by a clean install with no `auth.json` and empty `COMPOSER_AUTH`. If `livewire/flux-pro`
  is ever added it WILL need `FLUX_USERNAME` / `FLUX_LICENSE_KEY`, and those org secrets are
  currently NOT granted to this repository.

## Dependency install (fresh worktree)
- command: `composer install --no-interaction`
- post-install: `cp .env.example .env`; `php artisan key:generate`; ensure PostgreSQL databases
  `reel` and `reel_app_test` exist (`psql -h 127.0.0.1 -U root -d postgres -c 'CREATE DATABASE …'`);
  `php artisan migrate`.
- there is no Node step and must never be one.

## Harness map (role -> runtime; decorrelate model lineages)
- implementer: OpenCode (Solo `agent_tool_id 2`)
- quality reviewer: Codex (Solo `agent_tool_id 4`)
- acceptance judge: Claude (Solo `agent_tool_id 3`)

## Toolchain conformance — the ride-along rule (STANDING, all projects)

Run the project's full conformance command (`composer ready`, or the stack equivalent) as part of
FINALIZING every PR, and **let whatever it changes ride along in that PR** as a single isolated commit
titled `composer ready`.

The point is that conforming to the current standard is **passive** rather than something anyone has to
remember. Tools like Rector exist to keep the codebase at the current standard continuously; if their
output only lands when someone thinks to run them, the codebase drifts and the eventual catch-up is a huge
unreviewable diff.

This is **the boy scout policy: leave things better than you found them, even when the improvement is
not strictly related to the work at hand.**

- **Applies to EVERY project — ours and clients' alike — and to every PR regardless of size.** A
  one-line CI or YAML change gets the sweep exactly like a feature branch does. The only way it comes
  off is Ed specifying that deviation explicitly at the project or client level; a client exception is
  recorded there, never inferred.
- ⚠️ **Scope-discipline language does NOT suspend this rule.** "No unrelated cleanups", "one-line
  change only", and "don't expand scope" mean *don't invent extra work* — they never mean skip the
  ride-along. An agent that reads them that way will skip the sweep and cite the brief as
  justification. If you are writing a tightly-scoped brief, say so explicitly: *"no unrelated
  cleanups, but the standing `composer ready` ride-along still applies as its own commit."*
- **Do NOT open a separate branch for these changes.** As long as the tool CONFIGURATION is unchanged, the
  unrelated changes riding along on any given PR are small.
- **The one exception:** introducing a new Rector rule, or changing `pint.json` / equivalent tool config.
  That sweep is large and deliberate, so it gets its own dedicated branch and PR.
- Keep it in its OWN commit so a reviewer can separate "the feature" from "the sweep" at a glance.

## Ship details
- branch naming: `feat/<slug>`
- PR target repo: `artisan-build/reel`, branch `main`. **Never** open a PR against
  `artisan-build/laravel-nodeless` — that is the starter kit this app was scaffolded from, not this
  project.
- release / split steps: none today. Reel is deployed by the Built for Cloud control plane
  forking/connecting this repository, not by tagging a package. Revisit if `reel-client` becomes a
  path package here (see monorepo).

## Plan & coordination
- plan location: `docs/product/reel-prd.md` — the in-repo, decision-complete PRD. It is authoritative;
  do not depend on brain-only copies. PRD §16 defines the PR sequence, §17 records resolved decisions.
- Solo project: reel (id 45)
- run-log: `Reel coordinated build — run log` (Solo scratchpad; the coordinator appends at every
  transition)

## Stack notes / quirks
- **Nodeless by design: no Node, npm, Vite, or frontend build step. Do not introduce any.** The
  browser recorder in PRD §6 ships as a *precompiled* asset; building it must not add a Node step to
  this application's install or deploy.
- Scaffolded from `artisan-build/laravel-nodeless` at commit `6181182` (Laravel 13.12, Livewire 4,
  Flux 2.14, Fortify, Pest 4, PHPStan/Larastan level 6). The kit's own `CLAUDE.md`, `.solo/`, and
  starter-kit `export-ignore` rules were deliberately NOT carried over.
- `artisan-build/built-for-cloud` (^0.3, the BfC token + auth foundation) is NOT installed yet. It is
  PRD §16 step 1 work. When installing it: it augments the existing `users` table rather than owning
  it, so its guarded `is_admin` migration must run after the app's users migration, `is_admin` must be
  cast to boolean on `App\Models\User`, and it must stay OUT of `$fillable`.
- Laravel Cloud injects configuration for Cloud-provisioned resources into a separate managed env
  file. Never set env vars for a Cloud-provisioned resource — including connection selectors like
  `DB_CONNECTION`, `QUEUE_CONNECTION`, `CACHE_STORE` — or you shadow the injected value.
- `.cloud/` is gitignored: it is a local Cloud CLI binding, not project configuration.
- Rector's configured paths include `bootstrap/`, so it rewrites the generated, gitignored
  `bootstrap/cache/packages.php` whenever Laravel regenerates it. Harmless noise in `composer ready`
  output, not a gate failure. Narrowing the paths would be a `rector.php` config change, which the
  ride-along rule says gets its own dedicated branch and PR.
- PHPStan level 6 with a deliberately EMPTY `phpstan-baseline.neon`. Keep it empty; fix findings
  rather than baselining them.
- Local development PHP is 8.4; CI additionally covers 8.5. A gate that passes locally has only been
  proven on 8.4 — CI is what proves 8.5.
