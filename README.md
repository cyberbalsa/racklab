# RackLab

RackLab is a self-service educational lab platform for deploying course VMs and stacks on Proxmox VE. The current implementation is a greenfield Laravel 13 scaffold following the product requirements in `docs/prd/` and the Laravel architecture in `docs/superpowers/specs/2026-05-26-laravel-redesign.md`.

## Stack

- PHP 8.3+ with Laravel 13, Octane, and FrankenPHP for the app server.
- Livewire 4, Filament 5, Tailwind v4, and daisyUI 5 for UI surfaces.
- Pest 4, Pint, Larastan/PHPStan max level, Rector, Dusk, axe-core, and Lefthook for quality gates.
- First-party RackLab plugins live under `packages/racklab/*`; the scaffold includes `racklab/plugin-hello` as the path-package smoke test.

## Setup

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run hooks:install
```

## Development

Use FrankenPHP/Octane when it is installed:

```bash
composer dev
```

For a plain local smoke server:

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

The current scaffold exposes `/` for the RackLab welcome page and `/hello` for the Livewire smoke component.

## Quality Gates

```bash
composer pint:test
composer larastan
composer rector:dry
composer test
composer pest:tiny
composer pest:contract
composer pest:integration
npm run build
composer audit
npm audit --omit=dev
```

Browser tests use Laravel Dusk and axe-core. They require a Chrome or Chromium binary:

```bash
APP_URL=http://127.0.0.1:8000 composer pest:browser
```

The committed Lefthook config runs Pint, Larastan, Rector, and the Tiny Pest suite before commits, plus default tests, asset build, and dependency audits before pushes.

## Source Of Truth

- `AGENTS.md` / `CLAUDE.md` — agent orientation and engineering discipline.
- `docs/superpowers/specs/2026-05-26-laravel-redesign.md` — how RackLab is built.
- `docs/prd/` — what RackLab must do.
- `docs/roadmap/` — milestone slices and acceptance criteria.
- `PROGRESS.md` — current shipped state and recommended next slice.

## License

RackLab is licensed under Apache-2.0.
