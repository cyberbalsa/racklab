# Laravel Scaffold Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up a runnable Laravel 13 + Octane + FrankenPHP + Filament 5 + Livewire 4 skeleton with every quality gate from spec §8 wired up, so the next six sub-plans (tenancy-auth, plugin-lifecycle, realtime-replay, script-containers, ci-gates, and the eventual feature milestones) build on a disciplined base.

**Architecture:** Targeted overlay of Laravel's `laravel/laravel:^13.0` skeleton onto the existing `docs/`-only repo, then layered installs of Octane+FrankenPHP, Livewire 4, Filament 5, Tailwind v4 + daisyUI 5 (two-Vite-entry split for public + admin), Pint / Larastan / Rector / Pest 4 / Dusk + axe-core, the six custom Larastan rules in stub form, the `lefthook` pre-commit chain, and the GitHub Actions CI matrix from spec §8. A first-party Composer-package skeleton at `packages/racklab/plugin-hello/` lands as the contract-conformance reference for future plugins.

**Tech Stack:** PHP 8.3+, Laravel 13 (`^13.0`), Laravel Octane (`^2.0`) + FrankenPHP runtime, Livewire 4 (`^4.0`), Filament 5 (`^5.0`), Tailwind v4 (`^4.0`) + `@tailwindcss/vite` + daisyUI 5 (`^5.0`), Pest 4 (`^4.0`), Pint (`^1.0`), Larastan (`^3.0`) at PHPStan max level, Rector (`^2.0`), Laravel Dusk (`^8.0`) + `axe-core` via `@axe-core/playwright`-equivalent (`pa11y` or `axe-core` JS injection), `lefthook`, GitHub Actions.

---

## Scope reminder

In scope:

- Laravel 13 project skeleton overlaid on the current repo without clobbering `docs/`, `CLAUDE.md`, `AGENTS.md`, `PROGRESS.md`, `LICENSE`, or `.github/` (if present)
- Octane + FrankenPHP runtime, worker-mode dev server, max-requests cap
- Livewire 4 (single-file component pattern)
- Filament 5 admin panel (empty; no resources yet)
- Vite with two CSS entries: `resources/css/app.css` (public — Tailwind v4 + daisyUI 5 + Livewire styles) and `resources/css/filament.css` (admin — Filament vendor)
- Pint + Larastan max + Rector + Pest 4 (four test suites: tiny / contract / integration / browser) + Laravel Dusk + axe-core a11y assertion in Dusk
- Six custom Larastan rules (stub implementations that compile + register; real enforcement deepens in later sub-plans):
  - `UntenantedRule`, `NoLintOverridesRule`, `HookspecEventTypedRule`, `NoBareScopeBypassRule`, `NoSpatieBypassRule`, `NoBareEventDispatchOnHookspecsRule`
- `lefthook.yml` pre-commit hook running pint --test + larastan + rector --dry-run + pest tiny on staged files
- `.github/workflows/code-ci.yml` with the 19-job matrix from spec §8 (jobs that depend on later-arriving content — e.g. `scribe:generate --diff` — are wired but trivially pass on the scaffold)
- First-party plugin skeleton: `packages/racklab/plugin-hello/` (composer.json, ServiceProvider, Manifest interface stub, empty README)
- Hello-world Livewire 4 component + Pest tiny test + Dusk browser smoke test + axe-core assertion that proves the toolchain end-to-end
- README updates that document `composer install && npm install && npm run build && php artisan octane:start --server=frankenphp`

Out of scope (lands in subsequent sub-plans, do **not** add here):

- Sanctum / Fortify / Socialite / Track A JWT — `tenancy-auth`
- spatie/laravel-multitenancy / spatie/laravel-permission / `AccessResolver` / `CrossTenantFetch` — `tenancy-auth`
- owen-it/laravel-auditing + hash chain + audit_events three-tenant schema — `tenancy-auth`
- `App\Plugins\PluginRegistry`, `App\Plugins\HookDispatcher`, plugin lifecycle Artisan commands — `plugin-lifecycle`
- Reverb daemon + broadcast_event_log + `/api/v1/replay` — `realtime-replay`
- Horizon worker setup + per-job Podman containers + `ProviderConsoleProxy` — `script-containers`
- Scribe-OpenAPI schema-drift gate beyond placeholder + semgrep + axe-core-as-blocking-CI-gate beyond Dusk-smoke + i18n lang:check — `ci-gates`
- Proxmox codegen client — M03 (much later)

The scaffold ships **directories** for these future areas (`app/Domain/Tenancy/`, `app/Events/Hookspecs/`, `app/Plugins/`, etc.) as `.gitkeep` placeholders so the layout matches spec §4 from day one.

---

## File-structure map

Files this plan creates / modifies. Each gets one or more dedicated tasks; this index is your overview.

```text
.
├── composer.json                                — Modified: name, license, autoload-psr-4 for App/ + Packages/Racklab/PluginHello/Tests
├── composer.lock                                — Created (committed)
├── package.json                                 — Modified: tailwindcss, @tailwindcss/vite, daisyui, alpinejs-from-livewire bundling
├── package-lock.json                            — Created (committed)
├── artisan                                      — Created by Laravel skeleton
├── pint.json                                    — Created: Laravel preset, no overrides
├── phpstan.neon                                 — Created: Larastan max level, custom rules, no overrides
├── rector.php                                   — Created: Laravel + PHPUnit set lists, dry-run-friendly defaults
├── phpunit.xml                                  — Modified by Pest install; four <testsuite> entries
├── pest.php                                     — Created by Pest install (single tests/Pest.php)
├── lefthook.yml                                 — Created: pre-commit + commit-msg hooks
├── .env.example                                 — Modified: APP_NAME=RackLab, OCTANE_SERVER=frankenphp, OCTANE_MAX_REQUESTS=500
├── .gitignore                                   — Modified: append Laravel + npm + IDE entries; preserve existing entries
│
├── vite.config.js                               — Created: two CSS entries, TypeScript for islands
├── tailwind.config.* / @plugin in CSS           — Tailwind v4 is CSS-first; no JS config needed
│
├── app/
│   ├── Http/Controllers/Controller.php          — Laravel skeleton (untouched)
│   ├── Http/Kernel.php                          — Laravel skeleton
│   ├── Livewire/Hello.php                       — Created: hello-world single-file component
│   ├── Filament/                                — Created by `filament:install --panels`
│   │   └── (panel provider, scaffold)
│   ├── Domain/                                  — Created (.gitkeep placeholders)
│   │   ├── Rbac/.gitkeep
│   │   ├── Tenancy/.gitkeep
│   │   ├── Jobs/.gitkeep
│   │   ├── Audit/.gitkeep
│   │   ├── Quota/.gitkeep
│   │   └── Plugins/.gitkeep
│   ├── Events/Hookspecs/
│   │   └── .gitkeep
│   ├── Plugins/
│   │   └── .gitkeep
│   ├── Providers/
│   │   ├── AppServiceProvider.php               — Laravel skeleton
│   │   ├── Filament/AdminPanelProvider.php      — Created by filament:install
│   │   └── (no PluginServiceProvider yet — plugin-lifecycle sub-plan)
│   └── (no AuditEvent / RoleBinding / etc — those land in tenancy-auth)
│
├── packages/
│   └── racklab/
│       └── plugin-hello/
│           ├── composer.json                    — Created: psr-4, ServiceProvider declaration
│           ├── README.md                        — Created: 5-line overview
│           ├── src/
│           │   ├── PluginHelloServiceProvider.php   — Empty boot/register (lands in plugin-lifecycle)
│           │   └── Manifest.php                     — Stub implementing future Manifest interface
│           └── tests/
│               └── .gitkeep                         — Real tests land in plugin-lifecycle
│
├── resources/
│   ├── views/
│   │   ├── layouts/app.blade.php                — Created: default layout
│   │   └── livewire/hello.blade.php             — Created: hello-world view
│   ├── css/
│   │   ├── app.css                              — Created: Tailwind v4 + @plugin "daisyui"
│   │   └── filament.css                         — Created: Filament vendor CSS entry
│   ├── js/
│   │   ├── bootstrap.ts                         — Created: Livewire 4 + Echo (Echo wiring lands in realtime-replay)
│   │   └── islands/
│   │       └── .gitkeep                         — Real islands land in later sub-plans
│   └── lang/
│       └── en/
│           └── .gitkeep                         — Real translations land in tenancy-auth + features
│
├── routes/
│   ├── web.php                                  — Modified: add /hello route to Livewire component
│   ├── api.php                                  — Laravel skeleton (untouched; Sanctum wiring in tenancy-auth)
│   └── console.php                              — Laravel skeleton
│
├── database/
│   ├── migrations/                              — Laravel default migrations only (users, password_resets, etc.)
│   ├── factories/UserFactory.php                — Laravel skeleton
│   └── seeders/DatabaseSeeder.php               — Laravel skeleton
│
├── config/                                       — Laravel skeleton, with octane.php tuned (max-requests, terminate hooks)
│
├── tests/
│   ├── Pest.php                                 — Created by Pest install
│   ├── Tiny/
│   │   └── HelloComponentTest.php               — Pest tiny test for hello component
│   ├── Contract/
│   │   └── ContainerBootTest.php                — Pest contract test: Laravel app boots
│   ├── Integration/
│   │   └── DatabaseConnectionTest.php           — Pest integration test (Testcontainers wiring in tenancy-auth — for now sqlite in-memory)
│   ├── Browser/
│   │   └── HelloPageTest.php                    — Dusk smoke test + axe-core assertion
│   └── Larastan/Rules/
│       ├── UntenantedRule.php                   — Stub Larastan rule
│       ├── NoLintOverridesRule.php              — Stub
│       ├── HookspecEventTypedRule.php           — Stub
│       ├── NoBareScopeBypassRule.php            — Stub
│       ├── NoSpatieBypassRule.php               — Stub
│       └── NoBareEventDispatchOnHookspecsRule.php — Stub
│
└── .github/
    └── workflows/
        ├── code-ci.yml                          — Created: 19-job matrix per spec §8
        └── docs-ci.yml                          — Created (if absent): markdownlint + mermaid render
```

---

## Phase 0 — Pre-flight verification

### Task 1: Verify host toolchain

**Files:** none (read-only checks)

- [ ] **Step 1: Check PHP 8.3+ available**

```bash
php -v
```

Expected: `PHP 8.3.x` or `PHP 8.4.x` on the first line.

- [ ] **Step 2: Check Composer available**

```bash
composer --version
```

Expected: `Composer version 2.x.x` (any 2.x).

- [ ] **Step 3: Check Node 20+ and npm available**

```bash
node --version && npm --version
```

Expected: `v20.x` or later for node; `10.x` or later for npm.

- [ ] **Step 4: Check FrankenPHP binary available** (Octane will install it on first run if missing, but worth confirming)

```bash
which frankenphp || echo "frankenphp not yet installed — Octane will download on octane:install"
```

Either outcome is fine.

- [ ] **Step 5: Verify clean git state and confirm we are on `main`**

```bash
cd /home/fffics/Documents/projects/racklab
git status --short
git branch --show-current
```

Expected: `.claude/` untracked is OK; nothing else. Branch is `main`.

No commit for this task — verification only. If any check fails, escalate before continuing.

---

## Phase 1 — Laravel skeleton overlay

### Task 2: Overlay `laravel/laravel:^13.0` skeleton without clobbering existing repo

**Files:**
- Create (via overlay): `artisan`, `bootstrap/`, `config/`, `database/`, `public/`, `resources/`, `routes/`, `storage/`, `tests/Feature/`, `tests/Unit/`, `app/Http/`, `app/Models/`, `app/Providers/AppServiceProvider.php`, `composer.json`, `package.json`, `vite.config.js`, `phpunit.xml`, `.env.example`, `.editorconfig`, `.gitattributes`
- Preserve (do NOT overwrite): `docs/`, `CLAUDE.md`, `AGENTS.md`, `PROGRESS.md`, `LICENSE`, `.gitignore` (merge instead of replace), `.github/` if present, `.git/`

- [ ] **Step 1: Generate Laravel skeleton into a temp directory**

```bash
composer create-project --prefer-dist laravel/laravel:^13.0 /tmp/racklab-laravel-skeleton --no-install
```

Expected: directory `/tmp/racklab-laravel-skeleton/` created with `composer.json` declaring `laravel/framework:^13.0`. The `--no-install` flag skips the slow `composer install` step; we'll run it later under controlled conditions.

- [ ] **Step 2: Inspect what would be overlaid (dry-run rsync)**

```bash
rsync -avn --exclude='.git' --exclude='.github' --exclude='docs' --exclude='CLAUDE.md' --exclude='AGENTS.md' --exclude='PROGRESS.md' --exclude='LICENSE' --exclude='.gitignore' /tmp/racklab-laravel-skeleton/ /home/fffics/Documents/projects/racklab/
```

Expected: a long list of files-to-be-copied, none of which are in the preserve list. If any preserve-list file appears, stop and resolve.

- [ ] **Step 3: Perform the overlay**

```bash
rsync -av --exclude='.git' --exclude='.github' --exclude='docs' --exclude='CLAUDE.md' --exclude='AGENTS.md' --exclude='PROGRESS.md' --exclude='LICENSE' --exclude='.gitignore' /tmp/racklab-laravel-skeleton/ /home/fffics/Documents/projects/racklab/
```

Expected: rsync succeeds; the racklab repo now has `artisan`, `bootstrap/`, `composer.json`, etc.

- [ ] **Step 4: Merge `.gitignore` (append Laravel's entries that aren't already present)**

Read `/tmp/racklab-laravel-skeleton/.gitignore` and append any lines not already in `/home/fffics/Documents/projects/racklab/.gitignore`. Typical Laravel additions: `/vendor`, `/node_modules`, `/public/build`, `/public/hot`, `/storage/*.key`, `/.env*` except `/.env.example`, `Homestead.json`, `Homestead.yaml`, `.phpunit.result.cache`, `auth.json`, `.idea`.

(If `.gitignore` is absent in the repo, copy Laravel's verbatim.)

- [ ] **Step 5: Cleanup**

```bash
rm -rf /tmp/racklab-laravel-skeleton
```

- [ ] **Step 6: Edit `composer.json` to set RackLab metadata**

Replace the top-level `"name"`, `"description"`, `"license"`, `"keywords"` fields:

```json
{
  "name": "cyberbalsa/racklab",
  "type": "project",
  "description": "RackLab — self-service educational lab platform on Proxmox VE.",
  "keywords": ["laravel", "racklab", "proxmox", "lab", "education"],
  "license": "Apache-2.0",
  ...
}
```

Keep all other fields (require, autoload, scripts, etc.) as Laravel generated them.

- [ ] **Step 7: Commit**

```bash
git -C /home/fffics/Documents/projects/racklab add -A
git -C /home/fffics/Documents/projects/racklab commit -m "$(cat <<'EOF'
chore(scaffold): overlay Laravel 13 skeleton onto docs-only repo

Apply `laravel/laravel:^13.0` skeleton (artisan, bootstrap/, config/,
database/, public/, resources/, routes/, storage/, tests/, etc.) on
top of the existing docs-only RackLab repo without clobbering docs/,
CLAUDE.md, AGENTS.md, PROGRESS.md, LICENSE, .gitignore, or .github/.

Sets composer.json name to cyberbalsa/racklab and license to
Apache-2.0. No PHP dependencies installed yet — that happens in
task 4 once we know which optional dev deps we want from the start.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

### Task 3: Configure repo layout per spec §4 (placeholder directories)

**Files:**
- Create: `app/Domain/Rbac/.gitkeep`, `app/Domain/Tenancy/.gitkeep`, `app/Domain/Jobs/.gitkeep`, `app/Domain/Audit/.gitkeep`, `app/Domain/Quota/.gitkeep`, `app/Domain/Plugins/.gitkeep`
- Create: `app/Events/Hookspecs/.gitkeep`
- Create: `app/Plugins/.gitkeep`
- Create: `resources/js/islands/.gitkeep`
- Create: `resources/lang/en/.gitkeep`
- Create: `tests/Tiny/.gitkeep`, `tests/Contract/.gitkeep`, `tests/Integration/.gitkeep`, `tests/Browser/.gitkeep`, `tests/Larastan/Rules/.gitkeep`
- Create: `packages/racklab/.gitkeep` (placeholder; the `plugin-hello` package lands in Task 24)

- [ ] **Step 1: Create the directories with `.gitkeep` placeholders**

```bash
cd /home/fffics/Documents/projects/racklab
mkdir -p app/Domain/{Rbac,Tenancy,Jobs,Audit,Quota,Plugins}
mkdir -p app/Events/Hookspecs
mkdir -p app/Plugins
mkdir -p resources/js/islands
mkdir -p resources/lang/en
mkdir -p tests/{Tiny,Contract,Integration,Browser,Larastan/Rules}
mkdir -p packages/racklab
for dir in app/Domain/Rbac app/Domain/Tenancy app/Domain/Jobs app/Domain/Audit app/Domain/Quota app/Domain/Plugins app/Events/Hookspecs app/Plugins resources/js/islands resources/lang/en tests/Tiny tests/Contract tests/Integration tests/Browser tests/Larastan/Rules packages/racklab; do
  touch "${dir}/.gitkeep"
done
```

Expected: tree under each directory now contains a `.gitkeep` file.

- [ ] **Step 2: Verify the tree matches spec §4**

```bash
find app/Domain app/Events app/Plugins resources/js/islands resources/lang/en tests/Tiny tests/Contract tests/Integration tests/Browser tests/Larastan/Rules packages/racklab -name '.gitkeep' | sort
```

Expected: 15 `.gitkeep` files in the listed paths.

- [ ] **Step 3: Commit**

```bash
git -C /home/fffics/Documents/projects/racklab add -A
git -C /home/fffics/Documents/projects/racklab commit -m "$(cat <<'EOF'
chore(scaffold): add empty directory tree per redesign spec §4

app/Domain/, app/Events/Hookspecs/, app/Plugins/, resources/js/islands/,
resources/lang/en/, tests/{Tiny,Contract,Integration,Browser,Larastan/Rules}/,
and packages/racklab/ are placeholders for content landing in subsequent
sub-plans (tenancy-auth, plugin-lifecycle, realtime-replay, script-containers,
ci-gates). Land the directories now so the repo layout matches spec §4
from day one.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

### Task 4: Configure composer.json autoload and dev dependencies; run `composer install`

**Files:**
- Modify: `composer.json` (autoload sections + initial require-dev)

- [ ] **Step 1: Edit `composer.json` autoload to include `App\` (already present via Laravel skeleton) + `Racklab\PluginHello\` PSR-4 namespace for the in-monorepo plugin path repository**

Within the `"autoload"` block, the `"psr-4"` key already has `"App\\": "app/"`. Append a `"Racklab\\PluginHello\\": "packages/racklab/plugin-hello/src/"` entry — but only if it doesn't already exist:

```json
"autoload": {
  "psr-4": {
    "App\\": "app/",
    "Database\\Factories\\": "database/factories/",
    "Database\\Seeders\\": "database/seeders/",
    "Racklab\\PluginHello\\": "packages/racklab/plugin-hello/src/"
  }
},
```

- [ ] **Step 2: Add `tests/` autoload-dev paths for the four Pest test suites + Larastan rules**

Within `"autoload-dev"`:

```json
"autoload-dev": {
  "psr-4": {
    "Tests\\": "tests/",
    "Tests\\Tiny\\": "tests/Tiny/",
    "Tests\\Contract\\": "tests/Contract/",
    "Tests\\Integration\\": "tests/Integration/",
    "Tests\\Browser\\": "tests/Browser/",
    "Tests\\Larastan\\Rules\\": "tests/Larastan/Rules/"
  }
},
```

- [ ] **Step 3: Add the `packages/racklab/plugin-hello/` path repository in `composer.json`**

Add a `"repositories"` array at the top level (or extend if present):

```json
"repositories": [
  {
    "type": "path",
    "url": "packages/racklab/plugin-hello",
    "options": { "symlink": true }
  }
],
```

(Don't add the plugin to `require` yet — that happens in Task 24 once the package's own `composer.json` is in place.)

- [ ] **Step 4: Run `composer install`**

```bash
cd /home/fffics/Documents/projects/racklab
composer install --no-interaction
```

Expected: Laravel skeleton deps install cleanly; `vendor/` populated; `composer.lock` written.

- [ ] **Step 5: Generate the app encryption key**

```bash
cd /home/fffics/Documents/projects/racklab
cp .env.example .env
php artisan key:generate --ansi
```

Expected: `.env` now has `APP_KEY=base64:...`. `.env` is gitignored (Laravel's gitignore covers this).

- [ ] **Step 6: Smoke-test the Laravel skeleton**

```bash
cd /home/fffics/Documents/projects/racklab
php artisan --version
```

Expected: `Laravel Framework 13.x.y`.

- [ ] **Step 7: Run the default Laravel test suite to confirm bootstrap works**

```bash
cd /home/fffics/Documents/projects/racklab
php artisan test
```

Expected: 2 example tests pass (Laravel ships with one Unit + one Feature test). If they fail, the skeleton is misconfigured — escalate.

- [ ] **Step 8: Commit**

```bash
git -C /home/fffics/Documents/projects/racklab add composer.json composer.lock
git -C /home/fffics/Documents/projects/racklab commit -m "$(cat <<'EOF'
chore(scaffold): configure composer autoload + install Laravel 13 deps

Add Racklab\PluginHello\ psr-4 mapping for the future first-party
plugin (lands as a Composer path-repository in task 24). Add
Tests\{Tiny,Contract,Integration,Browser,Larastan\Rules}\ autoload-dev
paths matching the four Pest test suites and the custom Larastan
rule classes that land in phase 7.

composer install populates vendor/; composer.lock committed.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Phase 2 — Octane + FrankenPHP

### Task 5: Install and configure Laravel Octane with FrankenPHP runtime

**Files:**
- Modify: `composer.json` (add `laravel/octane:^2.0` to `require`)
- Create / Modify: `config/octane.php`, `.env.example` (add `OCTANE_SERVER=frankenphp`, `OCTANE_MAX_REQUESTS=500`)

- [ ] **Step 1: Require Octane**

```bash
cd /home/fffics/Documents/projects/racklab
composer require laravel/octane:^2.0
```

Expected: `composer.lock` updates; vendor/laravel/octane appears.

- [ ] **Step 2: Run Octane installer with FrankenPHP**

```bash
cd /home/fffics/Documents/projects/racklab
php artisan octane:install --server=frankenphp --no-interaction
```

Expected: Prompts for FrankenPHP download (use `--no-interaction` defaults). Creates `config/octane.php`. Downloads the `frankenphp` binary to `./frankenphp` (gitignored) or uses a system binary if present.

- [ ] **Step 3: Set Octane env defaults in `.env.example`**

Append to `/home/fffics/Documents/projects/racklab/.env.example`:

```env

OCTANE_SERVER=frankenphp
OCTANE_HOST=127.0.0.1
OCTANE_PORT=8000
# Octane state-leak guard per spec §5 + §8 — worker restarts every N requests
OCTANE_MAX_REQUESTS=500
```

- [ ] **Step 4: Document the state-leak hazard in `config/octane.php` comments**

In `config/octane.php`, at the top of the file's docblock (or as a top-of-file comment if no docblock), add:

```php
/*
|--------------------------------------------------------------------------
| Octane state-leak guard
|--------------------------------------------------------------------------
|
| RackLab runs Octane in worker mode under FrankenPHP. Per the redesign
| spec §5 and §8, all of these must be enforced at the application
| layer (this config is the operational guard):
|
| - max-requests cap (OCTANE_MAX_REQUESTS, default 500) — worker
|   recycles after this many requests, so accumulated static / singleton
|   state never lives forever.
| - SetTenantContextForOctane middleware MUST reset on response
|   (terminate()) — covered by the tenancy-auth sub-plan; not enforced
|   in this scaffold.
| - Pest contract test boots Octane and asserts two consecutive requests
|   for different tenants on the same worker never bleed context —
|   also tenancy-auth scope.
|
*/
```

- [ ] **Step 5: Add a `composer dev` script that runs Octane with `--watch` for development**

In `composer.json`, under `"scripts"`, add or extend:

```json
"scripts": {
  ...
  "dev": [
    "Composer\\Config::disableProcessTimeout",
    "@php artisan octane:start --server=frankenphp --watch"
  ],
  ...
}
```

- [ ] **Step 6: Verify Octane starts and a default Laravel route responds**

```bash
cd /home/fffics/Documents/projects/racklab
php artisan octane:start --server=frankenphp --port=8765 &
OCTANE_PID=$!
sleep 5
curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8765/
echo
kill $OCTANE_PID
wait $OCTANE_PID 2>/dev/null
```

Expected: `200` (Laravel default welcome page).

- [ ] **Step 7: Commit**

```bash
git -C /home/fffics/Documents/projects/racklab add composer.json composer.lock config/octane.php .env.example
git -C /home/fffics/Documents/projects/racklab commit -m "$(cat <<'EOF'
chore(scaffold): install Laravel Octane with FrankenPHP runtime

octane:install --server=frankenphp; config/octane.php gets a top-of-file
comment documenting the state-leak guards from spec §5 + §8 (max-requests
cap, terminate-time tenant-context reset, Pest contract test). The
SetTenantContextForOctane middleware and the contract test land in the
tenancy-auth sub-plan; this scaffold ships the operational cap and the
documentation.

.env.example gets OCTANE_SERVER=frankenphp + OCTANE_MAX_REQUESTS=500;
composer dev script runs octane:start with --watch.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Phase 3 — Livewire 4

### Task 6: Install Livewire 4 and configure single-file-component default

**Files:**
- Modify: `composer.json` (add `livewire/livewire:^4.0` to `require`)
- Modify: `config/livewire.php` (generated by `livewire:publish`)

- [ ] **Step 1: Require Livewire 4**

```bash
cd /home/fffics/Documents/projects/racklab
composer require livewire/livewire:^4.0
```

Expected: lock file updates.

- [ ] **Step 2: Publish Livewire config**

```bash
cd /home/fffics/Documents/projects/racklab
php artisan livewire:publish --config
```

Expected: `config/livewire.php` created. The default single-file-component setting is `true` in Livewire 4; no edits needed there.

- [ ] **Step 3: Verify `php artisan livewire` namespace is registered**

```bash
cd /home/fffics/Documents/projects/racklab
php artisan list | grep livewire
```

Expected: at least `livewire:make`, `livewire:publish`, `livewire:upgrade` commands present.

- [ ] **Step 4: Commit**

```bash
git -C /home/fffics/Documents/projects/racklab add composer.json composer.lock config/livewire.php
git -C /home/fffics/Documents/projects/racklab commit -m "$(cat <<'EOF'
chore(scaffold): install Livewire 4

composer require livewire/livewire:^4.0 + publish config. Single-file
components are the Livewire 4 default; no further config needed at
scaffold time. The hello-world Livewire component lands in task 13.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Phase 4 — Frontend (Tailwind v4 + daisyUI 5, two Vite entries)

### Task 7: Install Tailwind v4 + daisyUI 5 and configure `vite.config.js` with two CSS entries

**Files:**
- Modify: `package.json`
- Create: `resources/css/app.css`, `resources/css/filament.css`
- Modify: `vite.config.js`
- Create: `resources/js/bootstrap.ts`

- [ ] **Step 1: Install npm deps**

```bash
cd /home/fffics/Documents/projects/racklab
npm install -D tailwindcss@^4.0 @tailwindcss/vite@^4.0 daisyui@^5.0 typescript@^5.5
npm install
```

Expected: `node_modules/` populated; `package-lock.json` written.

- [ ] **Step 2: Replace `resources/css/app.css` with the Tailwind v4 + daisyUI 5 public entry**

Overwrite `resources/css/app.css` with:

```css
@import "tailwindcss";

@plugin "daisyui" {
    themes: light --default, dark;
}

/* Public-facing styles go here. Tailwind v4 is CSS-first; there is no
   tailwind.config.js. daisyUI 5 is loaded via the @plugin directive. */
```

- [ ] **Step 3: Create `resources/css/filament.css` as the admin entry**

Create `resources/css/filament.css`:

```css
/* Filament 5 admin panel CSS entry.
 *
 * Filament 5 ships its own Tailwind-v4-based vendor styles; we just
 * import them here. This file gets a separate Vite output so the
 * admin panel doesn't ship daisyUI to end users and the public-UI
 * bundle doesn't ship Filament's vendor styles to admins.
 *
 * Filament's filament:install command may rewrite this file later in
 * the scaffold (task 9); if so, this comment block is harmless to
 * keep at the top.
 */
@import "tailwindcss";
```

(Filament 5 will append its own `@source` and `@import` lines when `filament:install` runs in task 9.)

- [ ] **Step 4: Replace `vite.config.js` with the two-entry configuration**

Overwrite `vite.config.js`:

```javascript
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/filament.css',
                'resources/js/bootstrap.ts',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    build: {
        rollupOptions: {
            output: {
                manualChunks: {
                    'livewire': ['livewire'],
                },
            },
        },
    },
});
```

- [ ] **Step 5: Create `resources/js/bootstrap.ts` with the Livewire 4 init**

Create `resources/js/bootstrap.ts`:

```typescript
import './bootstrap-livewire';

// Livewire 4 bundles Alpine.js. The Livewire global is registered by the
// @livewireScripts Blade directive in the layout; this bootstrap file is
// reserved for non-Livewire frontend wiring (Echo / Pusher protocol client
// lands in the realtime-replay sub-plan; vanilla JS islands are imported
// from resources/js/islands/ as they land in later sub-plans).

declare global {
    interface Window {
        // Livewire global types extended here as needed
    }
}

export {};
```

And a sibling stub `resources/js/bootstrap-livewire.ts`:

```typescript
// Placeholder — Livewire's @livewireScripts directive injects the
// Livewire global at runtime. If a future sub-plan needs to programmatically
// hook into Livewire init events, that wiring goes here.
```

- [ ] **Step 6: Build the frontend bundles**

```bash
cd /home/fffics/Documents/projects/racklab
npm run build
```

Expected: `public/build/manifest.json` written; assets compiled for `app.css`, `filament.css`, `bootstrap.ts`. No build errors.

- [ ] **Step 7: Commit**

```bash
git -C /home/fffics/Documents/projects/racklab add package.json package-lock.json vite.config.js resources/css/app.css resources/css/filament.css resources/js/bootstrap.ts resources/js/bootstrap-livewire.ts
git -C /home/fffics/Documents/projects/racklab commit -m "$(cat <<'EOF'
chore(scaffold): wire Vite with Tailwind v4 + daisyUI 5 two-entry CSS split

Tailwind v4 is CSS-first; no tailwind.config.js. daisyUI 5 loaded via
@plugin "daisyui" directive in resources/css/app.css. Two Vite entries
per spec §2/§4: resources/css/app.css (public — Tailwind + daisyUI) and
resources/css/filament.css (admin — Filament will append its vendor
@source lines during filament:install). resources/js/bootstrap.ts is the
TypeScript entry; Livewire's @livewireScripts directive handles the
Livewire global injection at runtime.

npm install includes typescript@^5.5 to support the vanilla JS island
TypeScript sources that land in later sub-plans (realtime-replay's
xterm/noVNC islands; docs-plugin's TipTap island).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

### Task 8: Add a minimal Blade layout that pulls both Vite entries

**Files:**
- Create: `resources/views/layouts/app.blade.php`

- [ ] **Step 1: Create the layout**

Create `resources/views/layouts/app.blade.php`:

```blade
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'RackLab') }}</title>
    @vite(['resources/css/app.css', 'resources/js/bootstrap.ts'])
    @livewireStyles
</head>
<body class="min-h-screen bg-base-100 text-base-content">
    <main class="container mx-auto p-4">
        {{ $slot ?? '' }}
        @yield('content')
    </main>
    @livewireScripts
    @stack('scripts')
</body>
</html>
```

- [ ] **Step 2: Verify the layout renders without errors**

```bash
cd /home/fffics/Documents/projects/racklab
php -l resources/views/layouts/app.blade.php
```

(`php -l` doesn't fully validate Blade syntax but catches obvious PHP errors in the file. Real verification happens via the Dusk smoke test in Task 21.)

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git -C /home/fffics/Documents/projects/racklab add resources/views/layouts/app.blade.php
git -C /home/fffics/Documents/projects/racklab commit -m "$(cat <<'EOF'
chore(scaffold): add minimal Blade layout for public UI

resources/views/layouts/app.blade.php pulls the public Vite entry
(app.css + bootstrap.ts) and the Livewire @livewireStyles/@livewireScripts
directives. daisyUI's default light theme via data-theme="light";
container/main slot ready for the hello-world Livewire component in
task 13.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Phase 5 — Filament 5

### Task 9: Install Filament 5 and scaffold the empty admin panel

**Files:**
- Modify: `composer.json` (add `filament/filament:^5.0`)
- Modify: `resources/css/filament.css` (Filament will append its vendor `@source` lines)
- Create: `app/Providers/Filament/AdminPanelProvider.php` (created by `filament:install`)

- [ ] **Step 1: Require Filament 5**

```bash
cd /home/fffics/Documents/projects/racklab
composer require filament/filament:^5.0
```

Expected: lock file updates.

- [ ] **Step 2: Run the Filament installer for the admin panel**

```bash
cd /home/fffics/Documents/projects/racklab
php artisan filament:install --panels --no-interaction
```

Expected: prompts for panel name (default `admin`); creates `app/Providers/Filament/AdminPanelProvider.php` and `app/Filament/Resources/`, `app/Filament/Pages/`, etc. Registers the panel provider in `bootstrap/providers.php`.

- [ ] **Step 3: Verify Filament's vendor styles wired into `resources/css/filament.css`**

Filament's installer should have appended a `@source` line referencing its vendor CSS. If `resources/css/filament.css` still only contains the placeholder, manually append:

```css

@source "../../../vendor/filament/filament/resources/views/**/*.blade.php";
@source "../../../vendor/filament/filament/src/**/*.php";
@source "../../../app/Filament/**/*.php";
```

- [ ] **Step 4: Rebuild frontend bundles to include Filament**

```bash
cd /home/fffics/Documents/projects/racklab
npm run build
```

Expected: `public/build/manifest.json` now includes `filament.css` output with Filament's compiled styles.

- [ ] **Step 5: Verify `/admin` route registers**

```bash
cd /home/fffics/Documents/projects/racklab
php artisan route:list --path=admin --columns=method,uri,name
```

Expected: a `GET admin/login` row is present (Filament's default unauthenticated login route).

- [ ] **Step 6: Commit**

```bash
git -C /home/fffics/Documents/projects/racklab add composer.json composer.lock resources/css/filament.css app/Providers/Filament app/Filament bootstrap/providers.php
git -C /home/fffics/Documents/projects/racklab commit -m "$(cat <<'EOF'
chore(scaffold): install Filament 5 admin panel

filament:install --panels scaffolds an empty admin panel at /admin.
resources/css/filament.css now includes Filament's vendor @source lines
so npm run build produces the admin-only CSS bundle. The panel has no
Resources / Pages yet — admin features land in tenancy-auth and
subsequent feature sub-plans.

Filament 5 is Tailwind-v4-compatible out of the box; no extra theme
config needed at scaffold time. Tenancy (filament tenancy with
isPersistent: true per spec §5) wires up in the tenancy-auth sub-plan.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Phase 6 — Quality stack (Pint, Larastan, Rector, Pest, lefthook)

### Task 10: Configure Pint with the Laravel preset and the "no overrides" discipline

**Files:**
- Create: `pint.json`
- Modify: `composer.json` (`scripts` block; Pint comes pre-installed with Laravel 13)

- [ ] **Step 1: Create `pint.json`**

Create `/home/fffics/Documents/projects/racklab/pint.json`:

```json
{
    "preset": "laravel",
    "rules": {
        "declare_strict_types": true,
        "fully_qualified_strict_types": true,
        "global_namespace_import": {
            "import_classes": true,
            "import_constants": true,
            "import_functions": true
        },
        "no_unused_imports": true,
        "ordered_imports": {
            "sort_algorithm": "alpha"
        }
    },
    "exclude": [
        "bootstrap/cache",
        "storage",
        "vendor",
        "node_modules"
    ]
}
```

- [ ] **Step 2: Run Pint in check mode**

```bash
cd /home/fffics/Documents/projects/racklab
vendor/bin/pint --test
```

Expected: Either exits 0 (all clean) or exits non-zero with a list of files to fix. If non-zero, run `vendor/bin/pint` (no `--test`) and re-run `--test` until clean.

- [ ] **Step 3: Apply formatting if not yet clean**

```bash
cd /home/fffics/Documents/projects/racklab
vendor/bin/pint
vendor/bin/pint --test
```

Expected on second `--test` run: exits 0.

- [ ] **Step 4: Add a `composer pint` and `composer pint:test` script**

In `composer.json` under `"scripts"`:

```json
"pint": "@php vendor/bin/pint",
"pint:test": "@php vendor/bin/pint --test"
```

- [ ] **Step 5: Commit**

```bash
git -C /home/fffics/Documents/projects/racklab add pint.json composer.json $(git -C /home/fffics/Documents/projects/racklab diff --name-only HEAD)
git -C /home/fffics/Documents/projects/racklab commit -m "$(cat <<'EOF'
chore(scaffold): configure Pint with Laravel preset + strict-types rule

pint.json adopts the Laravel preset plus declare_strict_types,
global_namespace_import (imports promoted), no_unused_imports, and
alphabetical import ordering. Excludes bootstrap/cache, storage, vendor,
node_modules.

Per spec §8 / PRD §17 no-overrides discipline: inline Pint disables
are forbidden in production code; the formatter authoritatively rewrites
the source. CI enforces this in task 27.

composer pint / composer pint:test scripts added for ergonomics.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

### Task 11: Install Larastan at PHPStan max level + scaffold the six custom rules

**Files:**
- Modify: `composer.json` (add `larastan/larastan:^3.0` to `require-dev`)
- Create: `phpstan.neon`
- Create: `tests/Larastan/Rules/UntenantedRule.php`, `NoLintOverridesRule.php`, `HookspecEventTypedRule.php`, `NoBareScopeBypassRule.php`, `NoSpatieBypassRule.php`, `NoBareEventDispatchOnHookspecsRule.php`

- [ ] **Step 1: Require Larastan**

```bash
cd /home/fffics/Documents/projects/racklab
composer require --dev larastan/larastan:^3.0
```

Expected: lock file updates; vendor/larastan/larastan present.

- [ ] **Step 2: Create `phpstan.neon` at the repo root**

Create `/home/fffics/Documents/projects/racklab/phpstan.neon`:

```neon
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    level: max
    paths:
        - app
        - packages/racklab/plugin-hello/src
        - tests/Larastan/Rules
    excludePaths:
        - app/Filament/Resources/*Resource/Pages/*
    treatPhpDocTypesAsCertain: false
    checkMissingIterableValueType: true
    reportUnmatchedIgnoredErrors: true
    tmpDir: storage/framework/cache/phpstan

services:
    -
        class: Tests\Larastan\Rules\UntenantedRule
        tags:
            - phpstan.rules.rule
    -
        class: Tests\Larastan\Rules\NoLintOverridesRule
        tags:
            - phpstan.rules.rule
    -
        class: Tests\Larastan\Rules\HookspecEventTypedRule
        tags:
            - phpstan.rules.rule
    -
        class: Tests\Larastan\Rules\NoBareScopeBypassRule
        tags:
            - phpstan.rules.rule
    -
        class: Tests\Larastan\Rules\NoSpatieBypassRule
        tags:
            - phpstan.rules.rule
    -
        class: Tests\Larastan\Rules\NoBareEventDispatchOnHookspecsRule
        tags:
            - phpstan.rules.rule
```

- [ ] **Step 3: Create stub `UntenantedRule.php`**

Create `tests/Larastan/Rules/UntenantedRule.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Larastan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;

/**
 * Stub for the @untenanted CI gate from spec §8.
 *
 * Real implementation lands in the tenancy-auth sub-plan once the
 * Tenant model + the #[Untenanted] PHP attribute + the global TenantScope
 * are in place. At scaffold time, the rule applies to no nodes and
 * therefore trivially passes.
 *
 * @implements Rule<Node>
 */
final class UntenantedRule implements Rule
{
    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return array<int, RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // Stub — see tenancy-auth sub-plan for the real implementation.
        return [];
    }
}
```

- [ ] **Step 4: Create stub `NoLintOverridesRule.php`**

Create `tests/Larastan/Rules/NoLintOverridesRule.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Larastan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Forbids inline lint-override comments in production code per spec §8.
 *
 * Production code = anything under app/ or packages/racklab/*\/src/.
 * Forbidden comment patterns: @phpstan-ignore, @phpstan-ignore-line,
 * @phpstan-ignore-next-line, @psalm-suppress, @phpcs:ignore,
 * @phpcs:disable, eslint-disable, ts-ignore, ts-expect-error, noqa.
 *
 * Test code in tests/ is allowed two narrow exceptions; see spec §8.
 *
 * @implements Rule<Node>
 */
final class NoLintOverridesRule implements Rule
{
    private const FORBIDDEN_PATTERNS = [
        '@phpstan-ignore',
        '@psalm-suppress',
        '@phpcs:ignore',
        '@phpcs:disable',
        'eslint-disable',
        '@ts-ignore',
        '@ts-expect-error',
        '// noqa',
    ];

    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return array<int, RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $filename = $scope->getFile();

        // Only enforce on production paths
        if (! $this->isProductionPath($filename)) {
            return [];
        }

        $errors = [];

        foreach ($node->getComments() as $comment) {
            $text = $comment->getText();
            foreach (self::FORBIDDEN_PATTERNS as $pattern) {
                if (str_contains($text, $pattern)) {
                    $errors[] = RuleErrorBuilder::message(sprintf(
                        'Forbidden lint-override comment "%s" found in production code. Per spec §8 / PRD §17 no-overrides discipline, fix the underlying code or extend the rule — never silence the linter inline.',
                        $pattern
                    ))->line($comment->getStartLine())->build();
                }
            }
        }

        return $errors;
    }

    private function isProductionPath(string $filename): bool
    {
        $normalised = str_replace('\\', '/', $filename);

        return str_contains($normalised, '/app/')
            || preg_match('#/packages/racklab/[^/]+/src/#', $normalised) === 1;
    }
}
```

- [ ] **Step 5: Create stub `HookspecEventTypedRule.php`**

Create `tests/Larastan/Rules/HookspecEventTypedRule.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Larastan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;

/**
 * Stub for the HookspecEventTypedRule from spec §8.
 *
 * Real implementation: every class under app/Events/Hookspecs/**\/*Event.php
 * must be `final readonly` (or `final` with all properties readonly) and
 * have typed promoted-constructor properties. At scaffold time there are
 * no hookspec event classes yet (they land in plugin-lifecycle), so the
 * rule applies to no nodes.
 *
 * @implements Rule<Node>
 */
final class HookspecEventTypedRule implements Rule
{
    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return array<int, RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // Stub — see plugin-lifecycle sub-plan for the real implementation.
        return [];
    }
}
```

- [ ] **Step 6: Create stub `NoBareScopeBypassRule.php`**

Create `tests/Larastan/Rules/NoBareScopeBypassRule.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Larastan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;

/**
 * Stub for the NoBareScopeBypassRule from spec §8.
 *
 * Real implementation: any call to ->withoutGlobalScopes() or
 * ->withoutGlobalScope(TenantScope::class) outside
 * app/Domain/Tenancy/CrossTenantFetch.php is a security violation.
 * At scaffold time the TenantScope and CrossTenantFetch don't exist
 * yet (they land in tenancy-auth), so the rule applies to no nodes.
 *
 * @implements Rule<Node>
 */
final class NoBareScopeBypassRule implements Rule
{
    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return array<int, RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // Stub — see tenancy-auth sub-plan for the real implementation.
        return [];
    }
}
```

- [ ] **Step 7: Create stub `NoSpatieBypassRule.php`**

Create `tests/Larastan/Rules/NoSpatieBypassRule.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Larastan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;

/**
 * Stub for the NoSpatieBypassRule from spec §8.
 *
 * Real implementation: any call to $user->hasRole(...) or $user->can(...)
 * outside App\Domain\Tenancy\AccessResolver is a security violation —
 * AccessResolver is the only authorisation gatekeeper per spec §5.
 * At scaffold time the User model is unchanged Laravel default and
 * AccessResolver doesn't exist (lands in tenancy-auth), so the rule
 * applies to no nodes.
 *
 * @implements Rule<Node>
 */
final class NoSpatieBypassRule implements Rule
{
    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return array<int, RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // Stub — see tenancy-auth sub-plan for the real implementation.
        return [];
    }
}
```

- [ ] **Step 8: Create stub `NoBareEventDispatchOnHookspecsRule.php`**

Create `tests/Larastan/Rules/NoBareEventDispatchOnHookspecsRule.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Larastan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;

/**
 * Stub for the NoBareEventDispatchOnHookspecsRule from spec §8.
 *
 * Real implementation: any call to Event::dispatch(SomeHookspec\Event::class)
 * or Event::until(SomeHookspec\Event::class) outside
 * app/Plugins/HookDispatcher.php is a violation. All hookspec dispatch
 * must go through the typed HookDispatcher per spec §6. At scaffold time
 * HookDispatcher and the hookspec event classes don't exist (they land
 * in plugin-lifecycle), so the rule applies to no nodes.
 *
 * @implements Rule<Node>
 */
final class NoBareEventDispatchOnHookspecsRule implements Rule
{
    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return array<int, RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // Stub — see plugin-lifecycle sub-plan for the real implementation.
        return [];
    }
}
```

- [ ] **Step 9: Run Larastan**

```bash
cd /home/fffics/Documents/projects/racklab
vendor/bin/phpstan analyse --memory-limit=2G --no-progress
```

Expected: `[OK] No errors`. If errors are present, they're real type issues in the scaffold — fix each one (do not add `@phpstan-ignore`) until clean. Common scaffold-time fixes: add proper return types, narrow union types, fix `mixed` returns.

- [ ] **Step 10: Add `composer larastan` script**

In `composer.json` under `"scripts"`:

```json
"larastan": "@php vendor/bin/phpstan analyse --memory-limit=2G"
```

- [ ] **Step 11: Commit**

```bash
git -C /home/fffics/Documents/projects/racklab add composer.json composer.lock phpstan.neon tests/Larastan/Rules/
git -C /home/fffics/Documents/projects/racklab commit -m "$(cat <<'EOF'
chore(scaffold): install Larastan at PHPStan max + six custom-rule stubs

phpstan.neon includes Larastan's extension, sets level: max,
treatPhpDocTypesAsCertain: false, checkMissingIterableValueType: true,
reportUnmatchedIgnoredErrors: true. Scans app/, packages/racklab/plugin-hello/src/,
and tests/Larastan/Rules/.

The six custom Larastan rules from spec §8 ship as stub classes that
register with the analyser but produce no findings until their real
implementations land in later sub-plans:

- UntenantedRule (real impl: tenancy-auth)
- HookspecEventTypedRule (real impl: plugin-lifecycle)
- NoBareScopeBypassRule (real impl: tenancy-auth)
- NoSpatieBypassRule (real impl: tenancy-auth)
- NoBareEventDispatchOnHookspecsRule (real impl: plugin-lifecycle)
- NoLintOverridesRule (real impl: this scaffold — operational immediately
  because production code exists from day one; greps for @phpstan-ignore,
  @psalm-suppress, @phpcs:ignore/disable, eslint-disable, @ts-ignore,
  @ts-expect-error, // noqa in /app/ and /packages/racklab/*/src/).

vendor/bin/phpstan analyse exits clean against the current scaffold.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

### Task 12: Install Rector and configure the Laravel + Pest sets

**Files:**
- Modify: `composer.json` (add `rector/rector:^2.0` to `require-dev`)
- Create: `rector.php`

- [ ] **Step 1: Require Rector**

```bash
cd /home/fffics/Documents/projects/racklab
composer require --dev rector/rector:^2.0
```

- [ ] **Step 2: Create `rector.php`**

Create `/home/fffics/Documents/projects/racklab/rector.php`:

```php
<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/app',
        __DIR__ . '/packages/racklab/plugin-hello/src',
        __DIR__ . '/tests',
    ])
    ->withPhpSets(php83: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        privatization: true,
        instanceOf: true,
        earlyReturn: true,
    )
    ->withSkip([
        // Pest's higher-order tests use closure-based assertions that
        // Rector's coding-style set occasionally tries to "fix" away.
        \Rector\CodingStyle\Rector\Closure\StaticClosureRector::class => [
            __DIR__ . '/tests',
        ],
    ]);
```

- [ ] **Step 3: Run Rector in dry-run mode**

```bash
cd /home/fffics/Documents/projects/racklab
vendor/bin/rector process --dry-run --no-progress-bar
```

Expected: `[OK] 0 files would be changed` or an indication that Rector has no changes to suggest. If Rector suggests changes, **apply them** (run without `--dry-run`) and commit the result as part of this task — Rector at scaffold time is establishing the baseline.

- [ ] **Step 4: Add `composer rector` and `composer rector:dry` scripts**

```json
"rector": "@php vendor/bin/rector process",
"rector:dry": "@php vendor/bin/rector process --dry-run"
```

- [ ] **Step 5: Apply Rector changes (if any) and re-verify**

```bash
cd /home/fffics/Documents/projects/racklab
vendor/bin/rector process --no-progress-bar
vendor/bin/rector process --dry-run --no-progress-bar
```

Expected on second run: `[OK] 0 files would be changed`.

- [ ] **Step 6: Re-run Pint to clean up any post-Rector formatting drift**

```bash
cd /home/fffics/Documents/projects/racklab
vendor/bin/pint
vendor/bin/pint --test
```

Expected on `--test`: clean.

- [ ] **Step 7: Re-run Larastan to confirm Rector didn't introduce type regressions**

```bash
cd /home/fffics/Documents/projects/racklab
vendor/bin/phpstan analyse --memory-limit=2G --no-progress
```

Expected: clean.

- [ ] **Step 8: Commit**

```bash
git -C /home/fffics/Documents/projects/racklab add composer.json composer.lock rector.php $(git -C /home/fffics/Documents/projects/racklab diff --name-only HEAD)
git -C /home/fffics/Documents/projects/racklab commit -m "$(cat <<'EOF'
chore(scaffold): install Rector with Laravel + PHP 8.3 + dead-code sets

rector.php scopes to app/, packages/racklab/plugin-hello/src/, and tests/.
Uses withPhpSets(php83: true) and the prepared sets: deadCode, codeQuality,
codingStyle, typeDeclarations, privatization, instanceOf, earlyReturn.

Skips StaticClosureRector under tests/ because Pest's higher-order
tests use non-static closures intentionally.

composer rector / composer rector:dry scripts added. Baseline applied;
both Pint and Larastan pass after Rector's first sweep.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Phase 7 — Test infrastructure (Pest 4 + four suites + Dusk + axe-core)

### Task 13: Install Pest 4 and configure the four test suites

**Files:**
- Modify: `composer.json` (replace `phpunit/phpunit` with `pestphp/pest:^4.0` if Pest installer doesn't do it automatically)
- Modify: `phpunit.xml` (add four `<testsuite>` entries)
- Create: `tests/Pest.php` (single Pest config; replaces `tests/TestCase.php` extension declarations)

- [ ] **Step 1: Install Pest 4**

```bash
cd /home/fffics/Documents/projects/racklab
composer require --dev pestphp/pest:^4.0 pestphp/pest-plugin-laravel:^4.0 --with-all-dependencies
php artisan pest:install --no-interaction
```

Expected: `tests/Pest.php` created; `phpunit.xml` updated with Pest's bootstrap; `composer.lock` updates.

- [ ] **Step 2: Edit `phpunit.xml` to declare the four test suites**

Replace the `<testsuites>` block in `phpunit.xml`:

```xml
<testsuites>
    <testsuite name="tiny">
        <directory>tests/Tiny</directory>
    </testsuite>
    <testsuite name="contract">
        <directory>tests/Contract</directory>
    </testsuite>
    <testsuite name="integration">
        <directory>tests/Integration</directory>
    </testsuite>
    <testsuite name="browser">
        <directory>tests/Browser</directory>
    </testsuite>
    <testsuite name="default">
        <directory>tests/Tiny</directory>
        <directory>tests/Contract</directory>
        <directory>tests/Integration</directory>
    </testsuite>
</testsuites>
```

(The `browser` suite is explicitly NOT in `default` because Dusk needs a running app server.)

- [ ] **Step 3: Configure Pest's `tests/Pest.php`**

Overwrite `tests/Pest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The Pest 4 test runner discovers test files via the testsuite directives
| in phpunit.xml. Per spec §8, four suites:
|
| - tiny:        pure-PHP services, no Laravel boot
| - contract:    Laravel container + in-memory fakes (Storage::fake() etc.)
| - integration: real Postgres + Redis via Testcontainers (wired in
|                tenancy-auth; SQLite in-memory at scaffold time)
| - browser:     Laravel Dusk + axe-core
|
*/

uses(TestCase::class)->in('Contract');
uses(TestCase::class)->in('Integration');
uses(TestCase::class)->in('Browser');

// tests/Tiny does NOT use the TestCase — it's pure-PHP, no Laravel boot.
// Tiny tests construct fakes directly.

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

function fixturePath(string $relative): string
{
    return __DIR__ . '/Fixtures/' . ltrim($relative, '/');
}
```

- [ ] **Step 4: Verify Pest runs (no tests yet beyond the Laravel defaults)**

```bash
cd /home/fffics/Documents/projects/racklab
vendor/bin/pest --no-coverage
```

Expected: pass on the default Laravel example tests. Pest's banner shows the four suites.

- [ ] **Step 5: Add composer scripts**

In `composer.json` under `"scripts"`:

```json
"pest": "@php vendor/bin/pest --no-coverage",
"pest:tiny": "@php vendor/bin/pest --testsuite=tiny",
"pest:contract": "@php vendor/bin/pest --testsuite=contract",
"pest:integration": "@php vendor/bin/pest --testsuite=integration",
"pest:browser": "@php vendor/bin/pest --testsuite=browser",
"pest:coverage": "@php vendor/bin/pest --coverage --min=85"
```

- [ ] **Step 6: Commit**

```bash
git -C /home/fffics/Documents/projects/racklab add composer.json composer.lock phpunit.xml tests/Pest.php
git -C /home/fffics/Documents/projects/racklab commit -m "$(cat <<'EOF'
chore(scaffold): install Pest 4 + four test suites per spec §8

phpunit.xml declares four testsuites: tiny / contract / integration /
browser. Pest discovers files under tests/<suite>/. Tiny is pure-PHP
(no Laravel boot); contract, integration, and browser extend TestCase
via tests/Pest.php uses() directives.

The default suite (used by composer pest) excludes browser because
Dusk needs a live HTTP server. composer pest:* scripts target individual
suites; composer pest:coverage runs with the 85% baseline coverage gate
(real per-suite coverage gates per spec §8 — 90/80/70/named-flows — land
in the ci-gates sub-plan).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

### Task 14: Add a hello-world Livewire 4 component + Pest tiny test for it

**Files:**
- Create: `app/Livewire/Hello.php`
- Create: `resources/views/livewire/hello.blade.php`
- Modify: `routes/web.php` (add `/hello` route)
- Create: `tests/Tiny/HelloComponentTest.php`

- [ ] **Step 1: Write the failing Pest tiny test**

Create `tests/Tiny/HelloComponentTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Tiny;

use App\Livewire\Hello;

it('renders a greeting with the configured site name', function () {
    $component = new Hello();
    $component->mount();

    expect($component->greeting)->toBe('Hello, RackLab');
});

it('formats greeting with a custom subject', function () {
    $component = new Hello();
    $component->mount('Forrest');

    expect($component->greeting)->toBe('Hello, Forrest');
});
```

- [ ] **Step 2: Run the failing test**

```bash
cd /home/fffics/Documents/projects/racklab
vendor/bin/pest --testsuite=tiny --filter=HelloComponentTest
```

Expected: FAIL — `Class "App\Livewire\Hello" not found`.

- [ ] **Step 3: Create the Hello component**

Create `app/Livewire/Hello.php`:

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;

final class Hello extends Component
{
    public string $greeting = '';

    public function mount(string $subject = 'RackLab'): void
    {
        $this->greeting = sprintf('Hello, %s', $subject);
    }

    public function render(): View
    {
        return view('livewire.hello');
    }
}
```

- [ ] **Step 4: Create the Blade view**

Create `resources/views/livewire/hello.blade.php`:

```blade
<div>
    <h1 class="text-3xl font-bold">{{ $greeting }}</h1>
    <p class="mt-2 text-base-content/70">RackLab scaffold smoke test page.</p>
</div>
```

- [ ] **Step 5: Add the `/hello` route**

Edit `routes/web.php` and replace the default welcome route with:

```php
<?php

declare(strict_types=1);

use App\Livewire\Hello;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/hello', Hello::class)->name('hello');
```

- [ ] **Step 6: Run the tiny test again to verify it passes**

```bash
cd /home/fffics/Documents/projects/racklab
vendor/bin/pest --testsuite=tiny --filter=HelloComponentTest
```

Expected: PASS — 2 tests, 2 assertions.

- [ ] **Step 7: Run Pint + Larastan + Rector to ensure the new files are clean**

```bash
cd /home/fffics/Documents/projects/racklab
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=2G --no-progress
vendor/bin/rector process --dry-run --no-progress-bar
```

Expected: all three exit 0.

- [ ] **Step 8: Commit**

```bash
git -C /home/fffics/Documents/projects/racklab add app/Livewire/Hello.php resources/views/livewire/hello.blade.php routes/web.php tests/Tiny/HelloComponentTest.php
git -C /home/fffics/Documents/projects/racklab commit -m "$(cat <<'EOF'
feat(scaffold): add hello-world Livewire 4 component + Pest tiny test

App\Livewire\Hello is a minimal Livewire 4 component that formats a
greeting from a mount() parameter (default subject: 'RackLab'). Routed
at /hello via routes/web.php.

Tests\Tiny\HelloComponentTest exercises Hello directly (no Laravel
boot — it's pure-PHP construction and method invocation), proving the
tiny test layer works end-to-end.

The Dusk browser smoke test that proves the toolchain front-to-back
(curl /hello → axe-core a11y assertion) lands in task 17.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

### Task 15: Add a contract test that asserts the Laravel container boots cleanly

**Files:**
- Create: `tests/Contract/ContainerBootTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Contract/ContainerBootTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Contract;

use Illuminate\Contracts\Foundation\Application;

it('boots the Laravel container with the expected service bindings', function () {
    /** @var Application $app */
    $app = $this->app;

    expect($app)->toBeInstanceOf(Application::class)
        ->and($app->environment())->toBe('testing');
});

it('has the App namespace registered', function () {
    expect(class_exists(\App\Livewire\Hello::class))->toBeTrue();
});

it('has Filament registered when the panel provider is loaded', function () {
    expect(class_exists(\App\Providers\Filament\AdminPanelProvider::class))->toBeTrue();
});
```

- [ ] **Step 2: Run it**

```bash
cd /home/fffics/Documents/projects/racklab
vendor/bin/pest --testsuite=contract --filter=ContainerBootTest
```

Expected: PASS — 3 tests, ≥3 assertions. (The test was failing-because-of-class-not-found in earlier scaffold states; by this point in the plan the classes exist so the test passes on first run.)

- [ ] **Step 3: Commit**

```bash
git -C /home/fffics/Documents/projects/racklab add tests/Contract/ContainerBootTest.php
git -C /home/fffics/Documents/projects/racklab commit -m "$(cat <<'EOF'
test(scaffold): add Pest contract test asserting Laravel container boots

Tests\Contract\ContainerBootTest asserts: (1) the Laravel application
container instantiates in the testing environment; (2) App\Livewire\Hello
is autoloaded under the App\ namespace; (3) the Filament admin panel
provider class is autoloaded.

This proves the contract layer of the Pest 4 four-suite split is
operational (Laravel boot, container, fakes available) before any
real domain code lands.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

### Task 16: Add an integration test using SQLite in-memory (Testcontainers wiring deferred to tenancy-auth)

**Files:**
- Create: `tests/Integration/DatabaseConnectionTest.php`
- Modify: `phpunit.xml` (set `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:` for the integration suite — already the default from `tests/Pest.php` via Laravel's testing env, but make explicit)

- [ ] **Step 1: Confirm phpunit.xml has the right DB env defaults**

Open `phpunit.xml`. Within the `<php>` block at the bottom, confirm or add:

```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

(Laravel ships these by default in `phpunit.xml`; just verify they're present.)

- [ ] **Step 2: Write the integration test**

Create `tests/Integration/DatabaseConnectionTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('can run migrations and query the database', function () {
    expect(Schema::hasTable('users'))->toBeTrue();

    $count = DB::table('users')->count();
    expect($count)->toBe(0);
});

it('can insert and read a user row through the model', function () {
    \App\Models\User::factory()->create([
        'name' => 'Scaffold Smoke',
        'email' => 'scaffold@racklab.test',
    ]);

    expect(\App\Models\User::query()->where('email', 'scaffold@racklab.test')->exists())->toBeTrue();
});
```

- [ ] **Step 3: Run it**

```bash
cd /home/fffics/Documents/projects/racklab
vendor/bin/pest --testsuite=integration --filter=DatabaseConnectionTest
```

Expected: PASS — Laravel's default `users` migration runs against SQLite in-memory, the User factory creates a row, the assertion passes.

- [ ] **Step 4: Commit**

```bash
git -C /home/fffics/Documents/projects/racklab add tests/Integration/DatabaseConnectionTest.php phpunit.xml
git -C /home/fffics/Documents/projects/racklab commit -m "$(cat <<'EOF'
test(scaffold): add Pest integration test for DB boot (SQLite in-memory)

Tests\Integration\DatabaseConnectionTest exercises the integration
testsuite end-to-end: RefreshDatabase trait runs Laravel's default
migrations against SQLite in-memory, then the test inserts and reads
a User row through Laravel's factory + Eloquent.

The real integration tier per spec §8 uses Testcontainers (PHP binding)
to spin a real Postgres 16 + Redis 7 + Podman socket; that wiring lands
in the tenancy-auth sub-plan along with the Tenant model + multi-tenancy
test fixtures. For now SQLite in-memory proves the suite is operational.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

### Task 17: Install Laravel Dusk + write the browser smoke test with axe-core assertion

**Files:**
- Modify: `composer.json` (add `laravel/dusk:^8.0` to `require-dev`)
- Create: `tests/Browser/HelloPageTest.php`
- Create: `tests/Browser/Concerns/AssertsNoAxeViolations.php` (axe-core injection helper)
- Modify: `tests/Pest.php` if necessary to register the Dusk base class

- [ ] **Step 1: Require Dusk**

```bash
cd /home/fffics/Documents/projects/racklab
composer require --dev laravel/dusk:^8.0
php artisan dusk:install --no-interaction
```

Expected: Dusk installer creates `tests/DuskTestCase.php` and a Chrome driver. lock file updates.

- [ ] **Step 2: Create the axe-core injection helper**

Create `tests/Browser/Concerns/AssertsNoAxeViolations.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Browser\Concerns;

use Laravel\Dusk\Browser;

trait AssertsNoAxeViolations
{
    /**
     * Inject axe-core into the current page and assert zero a11y violations.
     *
     * Loads axe-core from a CDN (axe.min.js v4.x), runs axe.run() on the
     * full page, and fails the test if any violations are reported.
     *
     * Per spec §8 / PRD §17, axe-core in Dusk is the primary a11y gate;
     * every Dusk browser test should call this method on every page-load
     * snapshot once the toolchain matures.
     */
    public function assertNoAxeViolations(Browser $browser): Browser
    {
        $script = <<<'JS'
            (async () => {
                if (!window.axe) {
                    await new Promise((resolve, reject) => {
                        const s = document.createElement('script');
                        s.src = 'https://cdnjs.cloudflare.com/ajax/libs/axe-core/4.10.2/axe.min.js';
                        s.onload = resolve;
                        s.onerror = reject;
                        document.head.appendChild(s);
                    });
                }
                const results = await window.axe.run(document, {
                    runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'],
                });
                return {
                    violations: results.violations.map(v => ({
                        id: v.id,
                        impact: v.impact,
                        description: v.description,
                        nodes: v.nodes.length,
                    })),
                };
            })()
        JS;

        $result = $browser->script($script)[0];

        // Dusk's script() returns the promise resolution wrapped; depending
        // on driver this may be a JSON string or already-decoded array.
        if (is_string($result)) {
            $result = json_decode($result, true);
        }

        $violations = $result['violations'] ?? [];

        if (count($violations) > 0) {
            $summary = array_map(
                fn ($v) => sprintf('  - %s [%s] %s (%d nodes)', $v['id'], $v['impact'], $v['description'], $v['nodes']),
                $violations
            );
            throw new \PHPUnit\Framework\AssertionFailedError(
                "axe-core reported " . count($violations) . " a11y violation(s):\n" . implode("\n", $summary)
            );
        }

        return $browser;
    }
}
```

- [ ] **Step 3: Create the Dusk smoke test**

Create `tests/Browser/HelloPageTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\AssertsNoAxeViolations;
use Tests\DuskTestCase;

uses(DuskTestCase::class);
uses(AssertsNoAxeViolations::class);

it('renders the hello page with the expected greeting', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit('/hello')
            ->waitForText('Hello, RackLab')
            ->assertSee('Hello, RackLab');
    });
});

it('renders the hello page with zero axe-core a11y violations', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit('/hello')
            ->waitForText('Hello, RackLab');

        $this->assertNoAxeViolations($browser);
    });
});
```

- [ ] **Step 4: Start Octane in the background and run the browser test**

```bash
cd /home/fffics/Documents/projects/racklab
php artisan octane:start --server=frankenphp --port=8000 &
OCTANE_PID=$!
sleep 5
APP_URL=http://127.0.0.1:8000 vendor/bin/pest --testsuite=browser --filter=HelloPageTest
RESULT=$?
kill $OCTANE_PID
wait $OCTANE_PID 2>/dev/null
exit $RESULT
```

Expected: PASS — 2 browser tests pass. The hello page renders, and axe-core reports zero violations on the daisyUI-styled output.

If axe-core flags issues (e.g., missing `<html lang>`, missing `<title>`, low contrast on a daisyUI default), fix the layout in `resources/views/layouts/app.blade.php` and `resources/views/livewire/hello.blade.php` until clean.

- [ ] **Step 5: Commit**

```bash
git -C /home/fffics/Documents/projects/racklab add composer.json composer.lock tests/Browser/ tests/DuskTestCase.php $(git -C /home/fffics/Documents/projects/racklab diff --name-only HEAD)
git -C /home/fffics/Documents/projects/racklab commit -m "$(cat <<'EOF'
test(scaffold): add Dusk browser smoke test with axe-core a11y assertion

Tests\Browser\HelloPageTest proves the front-to-back toolchain:
- Octane + FrankenPHP serves the /hello route
- Livewire 4 renders the Hello component
- daisyUI 5 applies the styling
- axe-core runs against the rendered DOM and reports zero violations
  on the WCAG 2.1 AA rule set

The AssertsNoAxeViolations trait injects axe-core from a CDN and asserts
empty violations[] in the result. Per spec §8 / PRD §17, this is the
canonical a11y gate — every Dusk browser test the project ships from
now on should call $this->assertNoAxeViolations($browser) on every
page-load snapshot.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Phase 8 — Pre-commit hooks

### Task 18: Install lefthook and wire pint + larastan + rector + pest-tiny on staged files

**Files:**
- Modify: `package.json` (add `lefthook` to devDependencies — lefthook is distributed via npm for ease, or composer)
- Create: `lefthook.yml`

- [ ] **Step 1: Install lefthook via npm**

```bash
cd /home/fffics/Documents/projects/racklab
npm install -D lefthook
```

Expected: `lefthook` binary at `node_modules/.bin/lefthook`.

- [ ] **Step 2: Create `lefthook.yml`**

Create `/home/fffics/Documents/projects/racklab/lefthook.yml`:

```yaml
pre-commit:
  parallel: true
  commands:
    pint:
      glob: "*.php"
      run: vendor/bin/pint --test {staged_files}
      fail_text: "Pint formatting drift. Run `vendor/bin/pint` and re-stage."

    larastan:
      glob: "{app,packages,tests}/**/*.php"
      run: vendor/bin/phpstan analyse --memory-limit=2G --no-progress --error-format=raw {staged_files}
      fail_text: "Larastan max-level violation. Fix the type issue or extend the analyser rule; do NOT add @phpstan-ignore."

    rector:
      glob: "{app,packages,tests}/**/*.php"
      run: vendor/bin/rector process --dry-run --no-progress-bar {staged_files}
      fail_text: "Rector found refactor debt. Run `vendor/bin/rector process` and re-stage."

    pest-tiny:
      run: vendor/bin/pest --testsuite=tiny --no-coverage
      fail_text: "Pest tiny layer failed. Tiny tests must pass on every commit."

    markdownlint:
      glob: "*.md"
      run: npx -y markdownlint-cli2 {staged_files}
      fail_text: "Markdown lint issue. Fix per markdownlint-cli2 output."

commit-msg:
  commands:
    conventional:
      run: |
        head -1 {1} | grep -qE "^(feat|fix|chore|refactor|docs|test|perf|build|ci|style)(\(.+\))?: .+"
      fail_text: "Commit subject must follow Conventional Commits (feat:/fix:/chore:/etc.)."
```

- [ ] **Step 3: Install the hooks**

```bash
cd /home/fffics/Documents/projects/racklab
npx lefthook install
```

Expected: `.git/hooks/pre-commit` and `.git/hooks/commit-msg` symlinks created pointing at lefthook.

- [ ] **Step 4: Smoke-test the hooks by running them against the working tree**

```bash
cd /home/fffics/Documents/projects/racklab
npx lefthook run pre-commit --all-files
```

Expected: all five pre-commit commands pass against the existing tree.

- [ ] **Step 5: Commit**

```bash
git -C /home/fffics/Documents/projects/racklab add package.json package-lock.json lefthook.yml
git -C /home/fffics/Documents/projects/racklab commit -m "$(cat <<'EOF'
chore(scaffold): wire lefthook pre-commit with the quality stack

lefthook.yml runs in parallel on staged files:
- vendor/bin/pint --test on changed *.php
- vendor/bin/phpstan analyse on changed app/, packages/, tests/ *.php
- vendor/bin/rector process --dry-run on the same set
- vendor/bin/pest --testsuite=tiny (always, regardless of staged files)
- markdownlint-cli2 on changed *.md

A commit-msg hook enforces Conventional Commits subject prefixes.

Installs via npx lefthook install on each clone (documented in the
README in task 26). Subagent + human contributors get the same gates
without per-developer configuration.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Phase 9 — First-party plugin skeleton (`packages/racklab/plugin-hello/`)

### Task 19: Scaffold the `packages/racklab/plugin-hello/` Composer package skeleton

**Files:**
- Create: `packages/racklab/plugin-hello/composer.json`
- Create: `packages/racklab/plugin-hello/README.md`
- Create: `packages/racklab/plugin-hello/src/PluginHelloServiceProvider.php`
- Create: `packages/racklab/plugin-hello/src/Manifest.php`
- Modify: root `composer.json` (add `racklab/plugin-hello` to `require`)

- [ ] **Step 1: Replace `packages/racklab/.gitkeep` with the plugin-hello directory tree**

```bash
cd /home/fffics/Documents/projects/racklab
rm packages/racklab/.gitkeep
mkdir -p packages/racklab/plugin-hello/src
mkdir -p packages/racklab/plugin-hello/tests
touch packages/racklab/plugin-hello/tests/.gitkeep
```

- [ ] **Step 2: Create the plugin's `composer.json`**

Create `packages/racklab/plugin-hello/composer.json`:

```json
{
    "name": "racklab/plugin-hello",
    "type": "library",
    "description": "Reference first-party RackLab plugin. Exercises the Composer + ServiceProvider + typed hookspec event bus contract from spec §6.",
    "license": "Apache-2.0",
    "require": {
        "php": "^8.3"
    },
    "autoload": {
        "psr-4": {
            "Racklab\\PluginHello\\": "src/"
        }
    },
    "extra": {
        "racklab": {
            "plugin": true
        },
        "laravel": {
            "dont-discover": [
                "racklab/plugin-hello"
            ]
        }
    }
}
```

The `extra.racklab.plugin: true` flag is the marker `App\Plugins\PluginRegistry` will look for during plugin discovery (real registry lands in the plugin-lifecycle sub-plan).

The `extra.laravel.dont-discover` entry suppresses Laravel's standard package auto-discovery — RackLab plugins must go through the lifecycle gate (`racklab:plugin:enable`) before their ServiceProvider boots, per spec §6.

- [ ] **Step 3: Create the README**

Create `packages/racklab/plugin-hello/README.md`:

```markdown
# racklab/plugin-hello

Reference first-party RackLab plugin. Exercises the plugin contract from
[spec §6](../../../docs/superpowers/specs/2026-05-26-laravel-redesign.md):

- Composer-package + path-repository layout
- `"extra.racklab.plugin": true` marker for `PluginRegistry` discovery
- `"extra.laravel.dont-discover"` opt-out from Laravel's standard
  auto-discovery (RackLab plugins must go through
  `racklab:plugin:enable` before their ServiceProvider boots)
- Stub `PluginHelloServiceProvider` for the future `PluginRegistry` to boot
- Stub `Manifest` class for the future `RackLab\Plugins\Contracts\Manifest`
  interface (the interface itself lands in the `plugin-lifecycle` sub-plan)

At scaffold time the plugin is **structural only** — it ships zero
behaviour. The real plugin-hello implementation that exercises every
hookspec event style + RBAC contribution + audit emission + i18n catalog
lands in the `plugin-lifecycle` sub-plan.
```

- [ ] **Step 4: Create the stub ServiceProvider**

Create `packages/racklab/plugin-hello/src/PluginHelloServiceProvider.php`:

```php
<?php

declare(strict_types=1);

namespace Racklab\PluginHello;

use Illuminate\Support\ServiceProvider;

/**
 * RackLab plugin-hello ServiceProvider — stub.
 *
 * Real boot/register logic lands in the plugin-lifecycle sub-plan once
 * App\Plugins\PluginRegistry exists. At scaffold time this provider:
 *
 * - is NOT auto-discovered (composer.json extra.laravel.dont-discover)
 * - is NOT registered in bootstrap/providers.php (only PluginRegistry will
 *   register enabled plugins)
 * - boots no routes, no views, no commands, no listeners
 *
 * The class exists so PSR-4 autoload + Larastan + Pint all see it; the
 * future PluginRegistry will instantiate it once `racklab:plugin:enable
 * racklab/plugin-hello` runs.
 */
final class PluginHelloServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Stub — see plugin-lifecycle sub-plan.
    }

    public function boot(): void
    {
        // Stub — see plugin-lifecycle sub-plan.
    }
}
```

- [ ] **Step 5: Create the stub Manifest class**

Create `packages/racklab/plugin-hello/src/Manifest.php`:

```php
<?php

declare(strict_types=1);

namespace Racklab\PluginHello;

/**
 * Stub plugin manifest.
 *
 * The real `RackLab\Plugins\Contracts\Manifest` interface (declared
 * permissions, hookspec contributions, contributed UI surfaces, etc.)
 * lands in the plugin-lifecycle sub-plan. This class will implement
 * that interface once it exists; for now it ships the basic identity
 * fields PluginRegistry needs to find the plugin.
 */
final readonly class Manifest
{
    public function __construct(
        public string $slug = 'racklab/plugin-hello',
        public string $name = 'Hello (Reference Plugin)',
        public string $version = '0.1.0',
    ) {
    }
}
```

- [ ] **Step 6: Require the plugin in the root `composer.json` to confirm the path repository works**

In the root `composer.json` (already has the `repositories` entry from Task 4), add to `"require"`:

```json
"racklab/plugin-hello": "@dev"
```

- [ ] **Step 7: Run `composer update racklab/plugin-hello`**

```bash
cd /home/fffics/Documents/projects/racklab
composer update racklab/plugin-hello --no-interaction
```

Expected: the path repository symlinks `vendor/racklab/plugin-hello → ../../packages/racklab/plugin-hello`; lock file updates.

- [ ] **Step 8: Verify the plugin classes are autoloadable**

```bash
cd /home/fffics/Documents/projects/racklab
php -r "require 'vendor/autoload.php'; var_dump(class_exists('Racklab\\PluginHello\\PluginHelloServiceProvider')); var_dump(class_exists('Racklab\\PluginHello\\Manifest'));"
```

Expected: `bool(true)` printed twice.

- [ ] **Step 9: Run the quality stack against the new files**

```bash
cd /home/fffics/Documents/projects/racklab
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=2G --no-progress
vendor/bin/rector process --dry-run --no-progress-bar
vendor/bin/pest --no-coverage
```

Expected: all four exit 0.

- [ ] **Step 10: Commit**

```bash
git -C /home/fffics/Documents/projects/racklab add composer.json composer.lock packages/racklab/plugin-hello/
git -C /home/fffics/Documents/projects/racklab commit -m "$(cat <<'EOF'
feat(scaffold): scaffold the racklab/plugin-hello reference plugin

First first-party RackLab plugin landing under packages/racklab/plugin-hello/,
wired into the root composer.json as a path repository. The package:

- Declares `extra.racklab.plugin: true` — the future PluginRegistry's
  discovery marker
- Declares `extra.laravel.dont-discover` for itself — RackLab plugins
  go through the lifecycle gate (racklab:plugin:enable) before booting,
  not Laravel's auto-discovery (spec §6).
- Ships a stub PluginHelloServiceProvider (no behaviour yet) and a stub
  Manifest readonly DTO.
- Sets the precedent for in-monorepo plugins: own composer.json,
  Racklab\<PluginName>\ PSR-4 namespace, src/ for code, tests/ for tests.

Real plugin-hello functionality — exercising every hookspec event style
+ RBAC contribution + audit emission + i18n catalog — lands in the
plugin-lifecycle sub-plan.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Phase 10 — GitHub Actions CI

### Task 20: Create `.github/workflows/code-ci.yml` matching the spec §8 19-job matrix

**Files:**
- Create: `.github/workflows/code-ci.yml`

- [ ] **Step 1: Create the workflow**

Create `/home/fffics/Documents/projects/racklab/.github/workflows/code-ci.yml`:

```yaml
name: code-ci

on:
  pull_request:
    branches: [main]
  push:
    branches: [main]

env:
  COMPOSER_NO_INTERACTION: '1'

jobs:
  setup:
    runs-on: ubuntu-24.04
    strategy:
      fail-fast: false
      matrix:
        php: ['8.3', '8.4']
    outputs:
      php-versions: ${{ steps.matrix.outputs.json }}
    steps:
      - id: matrix
        run: echo 'json=${{ toJSON(matrix.php) }}' >> "$GITHUB_OUTPUT"
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: pcntl, posix, redis, pdo_pgsql, sqlite3, mbstring, intl, bcmath, exif, fileinfo, gd
          tools: composer:v2
      - uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: 'npm'
      - run: composer install --prefer-dist --no-progress
      - run: npm ci

  pint:
    needs: setup
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.3', tools: composer:v2 }
      - run: composer install --prefer-dist --no-progress
      - run: vendor/bin/pint --test

  larastan:
    needs: setup
    runs-on: ubuntu-24.04
    strategy:
      fail-fast: false
      matrix:
        php: ['8.3', '8.4']
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '${{ matrix.php }}', tools: composer:v2 }
      - run: composer install --prefer-dist --no-progress
      - run: vendor/bin/phpstan analyse --memory-limit=2G --no-progress

  rector:
    needs: setup
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.3', tools: composer:v2 }
      - run: composer install --prefer-dist --no-progress
      - run: vendor/bin/rector process --dry-run --no-progress-bar

  lockfile-check:
    needs: setup
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.3', tools: composer:v2 }
      - uses: actions/setup-node@v4
        with: { node-version: '20' }
      - run: composer validate --strict
      - run: |
          # composer.lock must match composer.json
          composer install --dry-run --prefer-dist --no-progress 2>&1 | tee /tmp/composer-install.log
          if grep -q "lock file is out of sync" /tmp/composer-install.log; then
            echo "::error::composer.lock is out of sync with composer.json"
            exit 1
          fi
      - run: npm ci

  pest-tiny:
    needs: setup
    runs-on: ubuntu-24.04
    strategy:
      fail-fast: false
      matrix:
        php: ['8.3', '8.4']
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '${{ matrix.php }}', tools: composer:v2, coverage: xdebug }
      - run: composer install --prefer-dist --no-progress
      - run: vendor/bin/pest --testsuite=tiny --no-coverage

  pest-contract:
    needs: setup
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.3', tools: composer:v2 }
      - run: composer install --prefer-dist --no-progress
      - run: vendor/bin/pest --testsuite=contract --no-coverage

  pest-integration:
    needs: setup
    runs-on: ubuntu-24.04
    services:
      postgres:
        image: postgres:16
        env:
          POSTGRES_USER: racklab
          POSTGRES_PASSWORD: racklab
          POSTGRES_DB: racklab_test
        ports: ['5432:5432']
        options: --health-cmd pg_isready --health-interval 10s --health-timeout 5s --health-retries 5
      redis:
        image: redis:7
        ports: ['6379:6379']
        options: --health-cmd "redis-cli ping" --health-interval 10s --health-timeout 5s --health-retries 5
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.3', tools: composer:v2 }
      - run: composer install --prefer-dist --no-progress
      - run: vendor/bin/pest --testsuite=integration --no-coverage
        env:
          DB_CONNECTION: pgsql
          DB_HOST: 127.0.0.1
          DB_PORT: 5432
          DB_DATABASE: racklab_test
          DB_USERNAME: racklab
          DB_PASSWORD: racklab
          REDIS_HOST: 127.0.0.1
          REDIS_PORT: 6379

  pest-browser:
    needs: setup
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.3', tools: composer:v2 }
      - uses: actions/setup-node@v4
        with: { node-version: '20', cache: 'npm' }
      - run: composer install --prefer-dist --no-progress
      - run: npm ci
      - run: npm run build
      - run: php artisan key:generate --env=testing
      - run: |
          php artisan octane:start --server=frankenphp --port=8000 &
          sleep 5
      - run: APP_URL=http://127.0.0.1:8000 vendor/bin/pest --testsuite=browser

  snapshot-roles:
    needs: setup
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.3', tools: composer:v2 }
      - run: composer install --prefer-dist --no-progress
      # Permission-snapshot test ships in tenancy-auth; placeholder until then.
      - run: echo "permission-snapshot gate placeholder — wired in tenancy-auth sub-plan"

  snapshot-audit:
    needs: setup
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@v4
      # Audit-emission gate ships in tenancy-auth; placeholder until then.
      - run: echo "audit-emission gate placeholder — wired in tenancy-auth sub-plan"

  openapi-drift:
    needs: setup
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@v4
      # Scribe gate ships in ci-gates; placeholder until then.
      - run: echo "OpenAPI schema-drift gate placeholder — wired in ci-gates sub-plan"

  composer-audit:
    needs: setup
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.3', tools: composer:v2 }
      - run: composer install --prefer-dist --no-progress
      - run: composer audit --abandoned=fail

  npm-audit:
    needs: setup
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with: { node-version: '20', cache: 'npm' }
      - run: npm ci
      - run: npm audit --production --audit-level=high

  security-scanners:
    needs: setup
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@v4
      # roave/security-advisories aborts at composer install if any
      # known-CVE deps are present; semgrep + enlightn/security-checker
      # land in the ci-gates sub-plan.
      - run: echo "security scanners (semgrep, enlightn/security-checker, phpcs-security-audit) placeholder — wired in ci-gates sub-plan"

  axe-core:
    needs: setup
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@v4
      # axe-core in Dusk runs inside pest-browser job (Task 17). This
      # standalone gate is for a future expansion (e.g., pa11y on critical
      # flows) per spec §8 ci-gates sub-plan.
      - run: echo "axe-core extended gate placeholder — primary axe-core gate runs inside pest-browser"

  lang-check:
    needs: setup
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.3', tools: composer:v2 }
      - run: composer install --prefer-dist --no-progress
      # racklab:lang:check custom artisan command lands in ci-gates sub-plan.
      - run: echo "racklab:lang:check (i18n catalog drift) placeholder — wired in ci-gates sub-plan"

  typescript:
    needs: setup
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with: { node-version: '20', cache: 'npm' }
      - run: npm ci
      - run: |
          if [ -d resources/js/islands ] && find resources/js/islands -name '*.ts' -type f -print -quit | grep -q .; then
            npx tsc --noEmit --strict --target ES2022 --moduleResolution node resources/js/islands/*.ts
          else
            echo "No vanilla JS island TypeScript sources yet — skip"
          fi

  eslint:
    needs: setup
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with: { node-version: '20', cache: 'npm' }
      - run: npm ci
      # ESLint config lands when the first vanilla JS island ships (realtime-replay
      # for xterm.ts / novnc.ts); placeholder until then.
      - run: echo "ESLint on vanilla JS island TS sources placeholder — wired when first island lands in realtime-replay"

  plugin-contract-smoke:
    needs: setup
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.3', tools: composer:v2 }
      - run: composer install --prefer-dist --no-progress
      # The full racklab:plugin install/migrate/enable/disable/rollback/uninstall
      # smoke test lands in the plugin-lifecycle sub-plan. For now, just verify
      # the plugin-hello package is autoloadable.
      - run: |
          php -r "require 'vendor/autoload.php'; if (!class_exists('Racklab\\PluginHello\\PluginHelloServiceProvider')) { exit(1); }"
          echo "plugin-hello package autoloads — full lifecycle gate lands in plugin-lifecycle sub-plan"
```

This workflow has 19 jobs matching the spec §8 19-job matrix. Several jobs are explicit "placeholder until X sub-plan" markers — they pass trivially on the scaffold but reserve the workflow slot so the gate can mature in-place as the dependent sub-plan lands.

- [ ] **Step 2: Lint the YAML locally if `yamllint` or `actionlint` is available**

```bash
cd /home/fffics/Documents/projects/racklab
which actionlint && actionlint .github/workflows/code-ci.yml || echo "actionlint not installed — CI will catch syntax errors on first run"
```

Either outcome is fine.

- [ ] **Step 3: Commit**

```bash
git -C /home/fffics/Documents/projects/racklab add .github/workflows/code-ci.yml
git -C /home/fffics/Documents/projects/racklab commit -m "$(cat <<'EOF'
ci: add code-ci.yml with the 19-job matrix from spec §8

Per-PR sequence runs in parallel where possible:

1.  setup (composer + npm install)
2.  pint (--test, format check)
3.  larastan (PHP 8.3 + 8.4 matrix, max level)
4.  rector --dry-run
5.  lockfile-check (composer validate + npm ci)
6.  pest-tiny (PHP 8.3 + 8.4 matrix)
7.  pest-contract
8.  pest-integration (real Postgres 16 + Redis 7 via GitHub services)
9.  pest-browser (Octane + FrankenPHP serve, Dusk + axe-core)
10. snapshot-roles (placeholder, lands in tenancy-auth)
11. snapshot-audit (placeholder, lands in tenancy-auth)
12. openapi-drift (placeholder, lands in ci-gates)
13. composer audit
14. npm audit
15. security-scanners (placeholder, lands in ci-gates)
16. axe-core extended (placeholder, primary gate runs inside pest-browser)
17. racklab:lang:check (placeholder, lands in ci-gates)
18. typescript --strict on resources/js/islands/*.ts (currently no-op)
19. eslint on island TS (placeholder, lands when first island ships)
plus plugin-contract-smoke (autoload-only at scaffold time; full
install/migrate/enable/disable/rollback/uninstall lifecycle smoke lands
in plugin-lifecycle sub-plan).

The placeholder jobs reserve the workflow slot so the gate can mature
in-place as each dependent sub-plan lands.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Phase 11 — Documentation + final verification

### Task 21: Update README.md with scaffold dev commands

**Files:**
- Modify: `README.md` (or create if absent)

- [ ] **Step 1: Inspect current README**

```bash
cd /home/fffics/Documents/projects/racklab
ls -la README.md 2>/dev/null && wc -l README.md 2>/dev/null || echo "No README.md yet"
```

- [ ] **Step 2: Overwrite or create `README.md`**

Create / overwrite `/home/fffics/Documents/projects/racklab/README.md`:

```markdown
# RackLab

Self-service educational lab platform replacing RIT's RLES. Students and instructors deploy VMs from an instructor-published catalog; admins run the platform on Proxmox VE.

## Stack

PHP 8.3+ / Laravel 13 / FrankenPHP + Octane / Livewire 4 / Filament 5 / Tailwind v4 + daisyUI 5 / Pest 4 / Larastan max / Rector / Dusk + axe-core / lefthook / GitHub Actions.

See `docs/superpowers/specs/2026-05-26-laravel-redesign.md` for the architectural spec, `docs/prd/` for functional requirements, `docs/roadmap/` for milestones, and `PROGRESS.md` for shipping state.

## Dev quickstart

Prerequisites: PHP 8.3+, Composer 2.x, Node 20+, npm 10+.

```bash
git clone git@github.com:cyberbalsa/racklab.git
cd racklab
composer install
npm install
npx lefthook install                     # install pre-commit hooks
cp .env.example .env
php artisan key:generate
npm run build
php artisan octane:start --server=frankenphp
```

The app runs at `http://127.0.0.1:8000`. The hello-world smoke route is at `/hello`. The Filament admin panel is at `/admin` (no users registered yet — admin auth lands in the `tenancy-auth` sub-plan).

## Quality stack

```bash
composer pint              # format with Pint (Laravel preset + strict types)
composer pint:test         # check format only (no writes)
composer larastan          # static analysis at PHPStan max + custom rules
composer rector            # automated refactor (apply)
composer rector:dry        # automated refactor (dry-run)
composer pest              # all four test suites except browser
composer pest:tiny         # pure-PHP unit tests
composer pest:contract     # Laravel-container tests with fakes
composer pest:integration  # SQLite in-memory now; Testcontainers later
composer pest:browser      # Dusk + axe-core (needs Octane running)
composer pest:coverage     # all with 85% threshold
```

Pre-commit hooks (via lefthook) run `pint --test`, `larastan`, `rector --dry-run`, and `pest --testsuite=tiny` on staged files. Commit subjects must follow Conventional Commits (`feat:` / `fix:` / `chore:` / `refactor:` / `docs:` / `test:` / etc.).

## Repo layout

```
app/                        Application code (Laravel + domain modules)
packages/racklab/*/         First-party plugins as in-monorepo Composer
                            packages (path-repository wired in root
                            composer.json)
resources/                  Blade views, CSS, JS islands, lang catalogs
routes/                     web.php / api.php / channels.php / console.php
database/                   migrations / factories / seeders
tests/                      Pest 4 four-suite layout + custom Larastan rules
docs/                       PRD, roadmap, architecture specs, plans
.github/workflows/          CI pipelines
```

Detailed layout per spec §4 in `docs/superpowers/specs/2026-05-26-laravel-redesign.md`.

## Next sub-plans

The `prd-rewrite` and `laravel-scaffold` sub-plans are complete. Remaining (in dependency order):

1. `tenancy-auth` — `AccessResolver`, `CrossTenantFetch`, RoleBinding, Sanctum + Fortify + Socialite + Track A JWT, AuditEvent + hash chain
2. `plugin-lifecycle` — `PluginRegistry`, `HookDispatcher`, lifecycle commands, hookspec event classes
3. `realtime-replay` — Reverb daemon, `broadcast_event_log`, `/api/v1/replay` endpoint
4. `script-containers` — Horizon + Podman job containers + `ProviderConsoleProxy`
5. `ci-gates` — full custom Larastan rules, snapshot gates, OpenAPI drift, semgrep, axe-core extended, `racklab:lang:check`

See `PROGRESS.md` and `docs/roadmap/README.md`.

## License

Apache-2.0. See `LICENSE`.
```

- [ ] **Step 3: Commit**

```bash
git -C /home/fffics/Documents/projects/racklab add README.md
git -C /home/fffics/Documents/projects/racklab commit -m "$(cat <<'EOF'
docs(scaffold): rewrite README.md for the Laravel scaffold

Replace any placeholder README with a working dev-quickstart: composer
install / npm install / lefthook install / key:generate / npm run build /
octane:start. Documents the composer pint/larastan/rector/pest scripts,
the four Pest suites, the repo layout per spec §4, and the next-sub-plan
sequence (tenancy-auth → plugin-lifecycle → realtime-replay →
script-containers → ci-gates).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

### Task 22: Update PROGRESS.md to mark `laravel-scaffold` complete

**Files:**
- Modify: `PROGRESS.md`

- [ ] **Step 1: Edit PROGRESS.md**

Open `/home/fffics/Documents/projects/racklab/PROGRESS.md`. Under the "Shipped" section, after the `prd-rewrite` entry, add:

```markdown
### laravel-scaffold sub-plan (2026-05-27 → <commit-date>)

The second of seven sub-plans is complete. The repo now hosts a runnable Laravel 13 + Octane + FrankenPHP + Filament 5 + Livewire 4 skeleton with every quality gate from spec §8 wired up:

- Laravel 13 skeleton overlaid onto the docs-only repo without clobbering `docs/`, `CLAUDE.md`, `AGENTS.md`, `PROGRESS.md`, `LICENSE`.
- Octane + FrankenPHP runtime; `OCTANE_MAX_REQUESTS=500` state-leak guard from spec §5 / §8.
- Livewire 4 + hello-world component + Pest tiny test that exercises it.
- Filament 5 empty admin panel scaffolded at `/admin`.
- Vite with two CSS entries: `app.css` (public — Tailwind v4 + daisyUI 5) and `filament.css` (admin — Filament vendor).
- Pest 4 with four test suites (tiny / contract / integration / browser).
- Pint, Larastan at PHPStan max + six custom Larastan rule stubs, Rector.
- `lefthook.yml` pre-commit running pint + larastan + rector + pest-tiny on staged files, plus a commit-msg hook enforcing Conventional Commits.
- Laravel Dusk + axe-core injection trait + browser smoke test asserting zero a11y violations on the hello page.
- First-party plugin skeleton: `packages/racklab/plugin-hello/` (Composer path repository, `extra.racklab.plugin: true`, `dont-discover` opt-out from Laravel auto-discovery).
- GitHub Actions `code-ci.yml` with the 19-job matrix from spec §8; jobs dependent on later sub-plans (snapshot gates, OpenAPI drift, lang-check, security scanners) ship as placeholders that pass trivially and reserve the workflow slot.
- `README.md` rewritten with dev-quickstart commands.

Next sub-plan: `tenancy-auth` — `AccessResolver`, `CrossTenantFetch`, `IdentifyTenant` + `SetTenantContextForOctane` + `BindTenantContext` middleware, `RoleBinding` model with `scope_type` + `tenant_set`, spatie/laravel-multitenancy + spatie/laravel-permission integration, Filament tenancy with `isPersistent: true`, Track A JWT issuer + JWKS endpoint + Sanctum PATs + Fortify + Socialite + OIDC + SAML, `AuditEvent` three-tenant schema + hash chain + `racklab:verify-audit-chain` Artisan command + bidirectional surfacing query.
```

- [ ] **Step 2: Commit**

```bash
git -C /home/fffics/Documents/projects/racklab add PROGRESS.md
git -C /home/fffics/Documents/projects/racklab commit -m "$(cat <<'EOF'
docs(scaffold): mark laravel-scaffold sub-plan complete in PROGRESS.md

The Laravel 13 + Octane + FrankenPHP + Filament 5 + Livewire 4
skeleton is runnable; every quality gate from spec §8 is wired up
(some as placeholders that mature in later sub-plans). Document the
delivered surface and point at the next sub-plan (tenancy-auth).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

### Task 23: Full end-to-end integration smoke

**Files:** none (read-only verification)

- [ ] **Step 1: Clean reset to a fresh-clone-equivalent state**

```bash
cd /home/fffics/Documents/projects/racklab
rm -rf vendor node_modules public/build storage/framework/cache/* bootstrap/cache/*
```

- [ ] **Step 2: Re-install dependencies**

```bash
cd /home/fffics/Documents/projects/racklab
composer install --prefer-dist --no-progress
npm ci
```

Expected: both succeed.

- [ ] **Step 3: Generate dev key and build assets**

```bash
cd /home/fffics/Documents/projects/racklab
cp .env.example .env
php artisan key:generate
npm run build
```

Expected: `.env` populated; `public/build/manifest.json` written.

- [ ] **Step 4: Run the full quality stack**

```bash
cd /home/fffics/Documents/projects/racklab
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=2G --no-progress
vendor/bin/rector process --dry-run --no-progress-bar
vendor/bin/pest --no-coverage
```

Expected: all four exit 0.

- [ ] **Step 5: Boot Octane and exercise the hello route**

```bash
cd /home/fffics/Documents/projects/racklab
php artisan octane:start --server=frankenphp --port=8000 &
OCTANE_PID=$!
sleep 5
curl -s -o /tmp/hello.html -w '%{http_code}' http://127.0.0.1:8000/hello
echo
grep 'Hello, RackLab' /tmp/hello.html
kill $OCTANE_PID
wait $OCTANE_PID 2>/dev/null
```

Expected: `200` status code; `Hello, RackLab` text appears in the rendered HTML.

- [ ] **Step 6: Run the browser test against the running Octane**

```bash
cd /home/fffics/Documents/projects/racklab
php artisan octane:start --server=frankenphp --port=8000 &
OCTANE_PID=$!
sleep 5
APP_URL=http://127.0.0.1:8000 vendor/bin/pest --testsuite=browser
RESULT=$?
kill $OCTANE_PID
wait $OCTANE_PID 2>/dev/null
test $RESULT -eq 0
```

Expected: browser tests pass; axe-core reports zero a11y violations.

- [ ] **Step 7: Verify the lefthook pre-commit hook runs cleanly**

```bash
cd /home/fffics/Documents/projects/racklab
npx lefthook run pre-commit --all-files
```

Expected: all pre-commit commands pass.

- [ ] **Step 8: Final commit (no changes; this task is verification only)**

If steps 1-7 all passed, no commit is needed. If any step failed, fix the underlying issue and create a new commit; do not amend.

```bash
cd /home/fffics/Documents/projects/racklab
git status --short
```

Expected: nothing to commit (working tree clean). The scaffold sub-plan is now complete.

---

## Self-review notes (writing-plans skill checklist)

1. **Spec coverage:**
   - Laravel 13 skeleton → Tasks 2-4
   - Octane + FrankenPHP → Task 5
   - Livewire 4 → Tasks 6, 14
   - Filament 5 → Task 9
   - Vite + Tailwind v4 + daisyUI 5 (two-entry) → Tasks 7-8
   - Pint → Task 10
   - Larastan + six custom rules → Task 11
   - Rector → Task 12
   - Pest 4 + four suites → Task 13
   - Hello-world component + Pest tiny test → Task 14
   - Contract test → Task 15
   - Integration test → Task 16
   - Dusk + axe-core browser test → Task 17
   - lefthook pre-commit → Task 18
   - First-party plugin skeleton (`packages/racklab/plugin-hello/`) → Task 19
   - GitHub Actions CI matrix → Task 20
   - README → Task 21
   - PROGRESS.md → Task 22
   - End-to-end smoke → Task 23
   - Spec §4 repo layout → Task 3

   No spec requirement uncovered.

2. **Placeholder scan:** No `TBD` / `TODO`. Every code step shows actual code. Every command step shows the exact command and expected output. CI workflow jobs that depend on later sub-plans are explicitly marked "placeholder until X sub-plan" — this is intentional documentation, not a placeholder gap in the plan.

3. **Type / naming consistency:**
   - `App\Livewire\Hello` used consistently across Tasks 14, 15, 17.
   - `Racklab\PluginHello\` namespace consistent across Tasks 4, 19.
   - `OCTANE_MAX_REQUESTS=500` consistent in Tasks 5 and 22.
   - Pest test paths (`tests/Tiny/`, `tests/Contract/`, etc.) consistent across all test tasks and the lefthook + CI configurations.
   - daisyUI 5 + Tailwind v4 versions consistent.
   - Custom Larastan rule names match spec §8 exactly: `UntenantedRule`, `NoLintOverridesRule`, `HookspecEventTypedRule`, `NoBareScopeBypassRule`, `NoSpatieBypassRule`, `NoBareEventDispatchOnHookspecsRule`.

4. **Out-of-order safety:** Each task is self-contained — file paths, commands, expected output, commit messages all complete per task. Tasks 14-17 (test layer smokes) have a natural ordering (tiny before contract before integration before browser) but each task's setup steps are independent.
