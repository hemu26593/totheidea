# totheidea

BMP Digital Platform — 1st Phase.

A Laravel 13 application. This repository currently contains the technical
foundation only; no BMP domain functionality has been built yet.

## Documentation

- [`docs/README.md`](docs/README.md) — technology stack, setup, how to run the
  application, run tests, and build frontend assets.
- [`docs/architecture-decisions.md`](docs/architecture-decisions.md) —
  architecture decision records.
- [`CLAUDE.md`](CLAUDE.md) — permanent architecture, AI, data isolation,
  security, and development process rules.

## Quick start

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan db:seed          # roles, permissions, initial Super Admin
npm install && npm run build
php artisan serve
```
