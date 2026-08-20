# Reel

**First-party session replay and Laravel error correlation for Built for Cloud.**

Reel is customer-owned browser session replay for debugging Laravel applications. It records a
privacy-filtered representation of a user's browser session, stores it in the customer's own
[Laravel Cloud](https://cloud.laravel.com) account, and lets an authorized person replay what
happened before a UI problem or server error.

Recordings stay in infrastructure you own. Artisan Build does not meter sessions or recordings and
runs no mandatory data plane for them.

> **Status: experimental, pre-launch.** This repository currently contains the application
> scaffold only. The recorder, ingest, player, retention, and correlation features described in the
> product requirements are not implemented yet.

The full product definition, scope, and build sequence live in
[`docs/product/reel-prd.md`](docs/product/reel-prd.md).

## Stack

- PHP 8.3+ / [Laravel](https://laravel.com) 13
- Livewire 4 + Flux, [Laravel Fortify](https://laravel.com/docs/fortify) for authentication
- PostgreSQL
- **Nodeless:** no Node, npm, Vite, or frontend build step. Do not introduce one.

Scaffolded from the [`artisan-build/laravel-nodeless`](https://github.com/artisan-build/laravel-nodeless)
starter kit.

## Local setup

Requires PHP 8.3+, Composer, and a PostgreSQL server.

```bash
composer install
cp .env.example .env
php artisan key:generate
createdb reel && createdb reel_app_test   # tests run against reel_app_test (see phpunit.xml)
php artisan migrate
composer dev                              # serve at http://localhost:8000
```

## Quality gate

`composer ready` is the single hard gate. It runs the IDE helpers, Rector, Pint, PHPStan, the Pest
suite, and a Composer security audit, and must be green before any pull request is reviewed:

```bash
composer ready
```

Individual pieces: `composer lint` (Pint), `composer lint:check` (Pint, check only),
`composer stan` (PHPStan/Larastan level 6), `composer test` (Pest), `composer report` (runs
everything non-blocking and prints each section).

CI enforces the same tools on every push and pull request to `main`: `.github/workflows/tests.yml`
(PHPStan + Pest on PHP 8.4 and 8.5 against PostgreSQL 16, then `composer audit`) and
`.github/workflows/lint.yml` (Pint).

## Contributing

Feature work follows the coordinated multi-agent build described in
[`.solo/workflow.md`](.solo/workflow.md).

## License

Reel is open-source software licensed under the [MIT license](LICENSE).
