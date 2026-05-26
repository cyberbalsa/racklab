# React-island toolchain skeleton — plan

**Slice:** M0 acceptance criteria lines 70–71.
**Date:** 2026-05-26.
**Status:** Plan (pre-codex review).
**Author:** Claude Opus 4.7 + Forrest.

## Goal

Land the minimum end-to-end React-island toolchain so a Django page can mount a
TypeScript-strict, ESLint-clean, Vitest-tested, Storybook-published, axe-clean
hello-island via django-vite. After this slice:

- `cd frontend && npm install && npm run dev` serves the Vite dev server.
- `cd frontend && npm run build` produces the manifest django-vite reads.
- `cd frontend && npm run test` runs Vitest + RTL + vitest-axe.
- `cd frontend && npm run storybook` serves Storybook 10.
- `cd frontend && npm run lint` runs ESLint with jsx-a11y + react + react-hooks (zero overrides).
- `cd frontend && npm run typecheck` runs `tsc --noEmit` strict.
- `manage.py runserver` renders `/hello-island/` which mounts a Mantine button + LinguiJS-translated heading.
- `pre-commit run --all-files` runs the new frontend hooks.
- CI runs the frontend gates alongside the Python ones.

The five M0 line-70 ingredients (package.json + Vite config + django-vite wiring
+ Mantine + LinguiJS + Storybook + Vitest + axe-core CI hook + hello-island)
and the M0 line-71 ingredients (ESLint + jsx-a11y + react + react-hooks +
Prettier in pre-commit) all converge in this single slice — the PRD acceptance
criterion is binary: either the whole pipeline works or M0 line 70 is open.

## In scope

Frontend toolchain rooted at `./frontend/`:

- `frontend/package.json` (npm + lockfile via `package-lock.json`; npm 10 ships
  with Node 22).
- `frontend/tsconfig.json` — TypeScript 6.0 strict (PRD §15 says "5.5+
  strict"; current latest is 6.0.3 — bumping the floor and recording the
  upgrade in PRD §15 + CLAUDE.md is part of this slice's commit).
- `frontend/vite.config.ts` — Vite 8 + `@vitejs/plugin-react-swc` + `@lingui/swc-plugin` SWC config + Vite manifest output for django-vite consumption.
- `frontend/eslint.config.mjs` — flat config (ESLint 10 default) with
  `eslint-plugin-jsx-a11y`, `eslint-plugin-react`, `eslint-plugin-react-hooks`,
  `@typescript-eslint`, `eslint-plugin-storybook`. **No overrides**, no
  per-file ignores beyond `node_modules` and `dist`.
- `frontend/.prettierrc.json` + `frontend/.prettierignore`.
- `frontend/vitest.config.ts` — jsdom env, vitest-axe matchers, RTL setup.
- `frontend/vitest.setup.ts` — `@testing-library/jest-dom/vitest`, vitest-axe
  matchers registration.
- `frontend/.storybook/main.ts` + `frontend/.storybook/preview.ts` — Storybook 10
  with `@storybook/addon-a11y` (parameters.a11y.test = 'error') and
  `@storybook/addon-vitest` for CI-failing a11y semantics.
- `frontend/lingui.config.ts` — LinguiJS v6 wired to `frontend/src/locales/` PO catalogs.
- `frontend/src/main.tsx` — entry that React-DOMs the hello island onto a
  Django-provided root element (`#hello-island-root`).
- `frontend/src/HelloIsland.tsx` — Mantine `Button` + LinguiJS `Trans` heading.
- `frontend/src/HelloIsland.test.tsx` — RTL render + vitest-axe `expect(axe(...)).toHaveNoViolations()`.
- `frontend/src/HelloIsland.stories.tsx` — Storybook CSF3 default + variant.
- `frontend/src/locales/en/messages.po` — extracted via Lingui's CLI.
- `frontend/src/i18n.ts` — Lingui i18n bootstrap with default `en` activation.
- `frontend/src/test-utils/render.tsx` — wraps RTL render with `MantineProvider` + `I18nProvider`.

Python side:

- Add `django-vite>=3.1,<4` to `[project.dependencies]` in `pyproject.toml`; bump
  the lockfile.
- Wire `django_vite` into `INSTALLED_APPS` + `DJANGO_VITE` settings dict in
  `src/racklab/settings/base.py` (manifest path + dev-server URL).
- Add `src/racklab/web/` Django app with a single `hello_island` view + a
  template `templates/web/hello_island.html` that uses `{% vite_asset 'src/main.tsx' %}`.
- URL route `path("hello-island/", views.hello_island)` under the project urlconf.

Lint + CI:

- Update `.pre-commit-config.yaml`:
  - `eslint` hook scoped to `frontend/**/*.{ts,tsx,js,jsx,mjs,cjs}` (entry: `npm --prefix frontend run lint -- --max-warnings 0`).
  - `prettier` check scoped to the same glob (entry: `npm --prefix frontend run format:check`).
  - `tsc` strict typecheck (entry: `npm --prefix frontend run typecheck`).
  - `vitest` tiny gate (entry: `npm --prefix frontend run test:ci`).
- Update `.github/workflows/code-ci.yml` to add a `frontend-quality` job
  alongside `python-quality`, running on Node 22:
  - `npm ci` from a fresh checkout.
  - `npm --prefix frontend run lint` (ESLint with `--max-warnings 0`).
  - `npm --prefix frontend run typecheck`.
  - `npm --prefix frontend run test:ci` (Vitest with coverage and vitest-axe).
  - `npm --prefix frontend run build` (Vite production build; confirms manifest writes).
  - `npm --prefix frontend run lingui:compile` (LinguiJS catalog compile is part of the test gate).
  - `npm --prefix frontend run storybook:test:a11y` (Storybook test-runner against axe; gated to `error`).
- `.gitignore`: add `frontend/node_modules/` + `frontend/dist/` + `frontend/.lingui-cache/` + `frontend/storybook-static/` + `frontend/coverage/`.

Docs:

- Update CLAUDE.md so the frontend stack TypeScript line reads "TypeScript 6.0 strict (currently 6.0.3)".
- Update PRD §15 line 16 (TypeScript pin) + PRD §17 line 220 (`tsc --noEmit`) to record 6.0.
- Update `docs/architecture/2026-05-25-django-library-survey.md` if it pins TS 5.5 explicitly (verify in the implementation step).
- Update `docs/roadmap/M00-foundations.md` line 42 to "TypeScript 6.0 strict".

## Out of scope (deferred)

- ESLint Stylistic / `eslint-plugin-import` ordering rules — defer to M10a when
  the React tree has more than two files.
- The full Mantine theme + dark-mode toggle + Mantine module CSS pipeline
  — M10a.
- Real DRF endpoint + TanStack Query usage — M1 + M2.
- Zustand store wired to the hello island — M10a.
- Zod schema validation against a DRF response — M1.
- `react-filepond` FilePond integration — M1+.
- Stylelint — M10a (no hand-authored CSS yet; Mantine handles styling).
- Playwright E2E + `@axe-core/playwright` — M10a (no E2E flow yet).
- pa11y — M10a.
- Multiple locales — `en` only; `es-ES` lands with M10b.
- Static-files collection wiring for prod — M0.5 (installer) plus M13a's
  Traefik front-end. M0 ships Vite dev + production build artifact, not the
  `collectstatic` integration story.

## File-by-file structure

```text
frontend/
├── package.json
├── package-lock.json
├── tsconfig.json
├── vite.config.ts
├── vitest.config.ts
├── vitest.setup.ts
├── eslint.config.mjs
├── .prettierrc.json
├── .prettierignore
├── lingui.config.ts
├── .storybook/
│   ├── main.ts
│   └── preview.ts
├── src/
│   ├── main.tsx
│   ├── HelloIsland.tsx
│   ├── HelloIsland.test.tsx
│   ├── HelloIsland.stories.tsx
│   ├── i18n.ts
│   ├── test-utils/
│   │   └── render.tsx
│   └── locales/
│       └── en/
│           └── messages.po
└── (built artifacts in dist/, ignored by git)

src/racklab/web/
├── __init__.py
├── apps.py
├── urls.py
├── views.py
└── templates/
    └── web/
        └── hello_island.html
```

## Pinned versions

All resolved via `npm view <pkg> version` on 2026-05-26 against the public
registry; lockfile pins exact versions.

| Package                              | Version    |
|--------------------------------------|------------|
| react                                | 19.2.6     |
| react-dom                            | 19.2.6     |
| @types/react                         | (matched)  |
| @types/react-dom                     | (matched)  |
| typescript                           | 6.0.3      |
| vite                                 | 8.0.14     |
| @vitejs/plugin-react-swc             | 4.3.1      |
| @mantine/core                        | 9.2.1      |
| @mantine/hooks                       | 9.2.1      |
| @lingui/core                         | 6.1.0      |
| @lingui/react                        | 6.1.0      |
| @lingui/vite-plugin                  | 6.1.0      |
| @lingui/cli                          | 6.1.0      |
| @lingui/swc-plugin                   | 6.3.0      |
| @tanstack/react-query                | 5.100.14   |
| zustand                              | 5.0.13     |
| zod                                  | 4.4.3      |
| vitest                               | 4.1.7      |
| @vitest/coverage-v8                  | 4.1.7      |
| @testing-library/react               | 16.3.2     |
| @testing-library/jest-dom            | 6.9.1     |
| @testing-library/user-event          | 14.6.1     |
| jsdom                                | 29.1.1     |
| vitest-axe                           | 0.1.0      |
| axe-core                             | 4.11.4     |
| storybook                            | 10.4.1     |
| @storybook/addon-a11y                | 10.4.1     |
| @storybook/addon-vitest              | 10.4.1     |
| @storybook/react-vite                | 10.4.1     |
| eslint                               | 10.4.0     |
| eslint-plugin-jsx-a11y               | 6.10.2     |
| eslint-plugin-react                  | 7.37.5     |
| eslint-plugin-react-hooks            | 7.1.1      |
| eslint-plugin-storybook              | (latest)   |
| @typescript-eslint/eslint-plugin     | (latest)   |
| @typescript-eslint/parser            | (latest)   |
| prettier                             | 3.8.3      |

`django-vite` 3.1.0 added to `pyproject.toml` `[project.dependencies]`.

`vitest-axe` 0.1.0 is the named choice (PRD §17 line 183) but the package has
not seen a release in ~13 months. The 1.0.0-pre.5 tag exists but is also stale.
**Risk noted in this plan; codex review explicitly asked.** If codex flags
this as too stale, the fallback is `jest-axe@10.0.0` + a manual matcher
adapter for Vitest (jest-axe is actively maintained).

## Open questions for codex

1. **vitest-axe staleness.** Should we proceed with `vitest-axe@0.1.0` per the
   PRD or pre-emptively switch to `jest-axe@10.0.0` (actively maintained)?
2. **TypeScript 6.0 bump.** PRD §15/§17 say "5.5+". Current latest is 6.0.3.
   Plan: pin 6.0 since the PRD floor is satisfied. OK to update PRD prose +
   CLAUDE.md to "6.0+ strict" in the same commit, or split into a doc-only
   precursor commit?
3. **Frontend dir location.** `frontend/` at repo root vs `src/racklab/frontend/`
   inside the Python package vs `assets/`? Plan: `frontend/` at root because the
   wheel target only ships `src/racklab/` and we don't want node_modules in the
   wheel.
4. **Pre-commit hook entry style.** Plan uses `npm --prefix frontend run <cmd>`
   so the hook runs from the repo root without `cd`. Alternative: a wrapper
   script in `scripts/frontend-<cmd>.sh`. Plan as-is unless codex disagrees.
5. **Storybook a11y CI failure mechanism.** Plan: Storybook test-runner
   (`@storybook/test-runner@0.24.4`) + `@axe-core/playwright` against a built
   Storybook (`storybook-static/`). PRD §17 line 222 says the Vitest addon path
   is workable. Plan picks test-runner for simplicity (a single static build +
   axe scan); flag for codex.
6. **Hello island Django URL.** Plan: `/hello-island/` is an unauthenticated,
   throwaway demo page. Should it be gated behind `DEBUG=True` to avoid shipping
   a demo route in prod? Plan: yes, gate.

## Implementation order

1. `frontend/package.json` + `frontend/package-lock.json` via `npm install` of
   the pinned versions.
2. `frontend/tsconfig.json` (strict + `react-jsx`).
3. `frontend/vite.config.ts` (Vite 8 manifest output + Lingui SWC plugin).
4. `frontend/eslint.config.mjs` + `frontend/.prettierrc.json` + `frontend/.prettierignore`.
5. `frontend/vitest.config.ts` + `frontend/vitest.setup.ts`.
6. `frontend/lingui.config.ts` + `frontend/src/locales/en/messages.po`.
7. `frontend/.storybook/main.ts` + `frontend/.storybook/preview.ts`.
8. `frontend/src/i18n.ts` + `frontend/src/main.tsx` + `frontend/src/HelloIsland.tsx`.
9. `frontend/src/test-utils/render.tsx`.
10. `frontend/src/HelloIsland.test.tsx` — Vitest + RTL + vitest-axe.
11. `frontend/src/HelloIsland.stories.tsx` — Storybook CSF3.
12. `src/racklab/web/` Django app + URL route + template.
13. `pyproject.toml` django-vite dep + lockfile bump.
14. `src/racklab/settings/base.py` django-vite settings.
15. `.pre-commit-config.yaml` — frontend hooks.
16. `.github/workflows/code-ci.yml` — `frontend-quality` job.
17. `.gitignore` — frontend artifacts.
18. PRD §15 + §17 + roadmap M00 TypeScript bump prose updates.
19. CLAUDE.md TypeScript bump prose updates.

## Verification

After every implementation step:

- `cd frontend && npm run typecheck` (zero errors).
- `cd frontend && npm run lint` (zero warnings, ESLint `--max-warnings 0`).
- `cd frontend && npm run format:check`.
- `cd frontend && npm run test:ci`.
- `cd frontend && npm run build`.
- `cd frontend && npm run storybook:test:a11y` (gated to error).
- `uv run pytest` (no Python regressions; Django app loads).
- `uv run python manage.py check`.
- `uv run python manage.py runserver` + `curl http://localhost:8000/hello-island/` (manual smoke).
- `uv run pre-commit run --all-files`.

## Risks

- **vitest-axe staleness** (open question 1 above).
- **Storybook 10 + Vite 8 compat**: Storybook 10 is recent; verify
  `@storybook/react-vite` 10.4.1 supports Vite 8. Codex prompt explicitly
  asks.
- **Lingui SWC plugin + Vite 8**: `@lingui/swc-plugin` 6.3.0 might lag the
  Vite 8 SWC binary. Fallback: drop the SWC plugin and use
  `@lingui/babel-plugin-lingui-macro` via `@vitejs/plugin-react` (babel)
  instead of `-react-swc`. Performance hit but functionally identical.
- **CI cache size**: `npm ci` from a clean checkout pulls ~600 MB of deps.
  Use `actions/setup-node@v4` with `cache: 'npm'`.
- **No-overrides discipline on the frontend tree**: ESLint `--max-warnings 0`
  is set so the discipline transfers from Python. The `no-lint-overrides`
  pre-commit hook already covers `// eslint-disable`, `// @ts-ignore`, and
  `// @ts-expect-error` patterns (verified in
  `scripts/check-no-lint-overrides.sh`).

## Codex review prompt (drafted)

```text
Review docs/superpowers/plans/2026-05-26-react-island-toolchain-skeleton.md.

Goal: land the M0 acceptance lines 70–71 React-island toolchain skeleton in a
single slice — package.json + Vite 8 + django-vite 3.1 + Mantine 9.2.1 +
LinguiJS 6 + Storybook 10 + Vitest 4 + vitest-axe + ESLint (jsx-a11y, react,
react-hooks) + Prettier + TypeScript 6 strict + a hello island that renders
a Mantine button + LinguiJS-translated heading mounted into a Django template
via {% vite_asset %}, plus pre-commit and CI hooks for all of the above.

Constraints:
- TDD discipline: hello-island has a Vitest + RTL + vitest-axe test.
- No-overrides discipline: ESLint --max-warnings 0; no // eslint-disable, no
  // @ts-ignore, no // @ts-expect-error in production code.
- Pre-commit + CI run the new gates.
- Frontend lives in ./frontend/ (separate from the Python wheel target
  src/racklab/).

Findings I want, in priority order:
- P0/P1 correctness bugs: wrong dep version pin, incompatible major versions,
  missing config (e.g., LinguiJS SWC plugin not actually compatible with
  Vite 8 + plugin-react-swc 4.3.1).
- vitest-axe staleness — should we use jest-axe@10 instead? The PRD says
  vitest-axe but it hasn't shipped in 13 months.
- TypeScript 6.0 bump from PRD-stated 5.5: OK to bump in same commit or
  split into a doc-only precursor commit?
- Storybook 10 + Vite 8 + @storybook/react-vite 10.4.1 compat: should we use
  Storybook's test-runner (current plan) or the addon-vitest path for CI a11y
  failure semantics?
- Pre-commit hook entry style: `npm --prefix frontend run lint`. Any
  reason to prefer a wrapper script under scripts/?
- Static-file collection for prod: deferred to M0.5 / M13a. Confirm this is
  the right boundary.
- Hello-island URL gating: plan gates the demo URL behind DEBUG=True. OK?
- File-by-file structure: any obvious gaps for the toolchain pieces?

Be terse. Prioritize by severity. Don't restate the plan.
```
