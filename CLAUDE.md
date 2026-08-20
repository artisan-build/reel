# Reel

Customer-owned browser session replay for debugging Laravel applications, deployed on Laravel Cloud
as an experimental Built for Cloud product. Public repository, MIT licensed.

The authoritative product definition — scope, decisions, and the build sequence — is
`docs/product/reel-prd.md`. Read it before proposing product behavior; do not rely on external or
brain-only copies of it.

**Status: pre-launch.** The repository is the application scaffold plus its quality gate. No
recorder, ingest, player, retention, or correlation code exists yet. Features land through the PR
sequence in PRD §16.

## Workflow

Feature builds: see `.solo/workflow.md` and the `multi-agent-build` skill.

Hard gate before any PR is reviewed: `composer ready` (ide-helper + rector + pint + phpstan + pest +
composer audit), green on a clean, committed SHA.

## Stack

- PHP 8.3+ / Laravel 13, Livewire 4 + Flux, Fortify for auth, Pest 4, PHPStan/Larastan level 6.
- PostgreSQL. Tests run against a real `reel_app_test` database (`phpunit.xml`), not SQLite —
  production is Postgres on Cloud and the suite should not diverge from it.
- **Nodeless: no Node, npm, Vite, or frontend build step. Do not introduce any.** Assets are handled
  by `laravel/chisel` and the `optimize:tailwind` command.

## IDE helper files

`_ide_helper.php` and `_ide_helper_models.php` are committed on purpose (PHPStan scans
`_ide_helper_models.php`). `.phpstorm.meta.php` is gitignored on purpose (it embeds absolute local
paths). This asymmetry is deliberate — do not make them consistent in either direction.
