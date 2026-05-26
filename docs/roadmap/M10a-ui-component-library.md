# M10a — UI Component Library + Page Shell

**Status:** Not started.
**Estimated effort:** 3–4 weeks.
**Depends on:** M1 through M9 (including M5a/M5b and M7a/M7b).
**Unblocks:** M10b.

## Goal

Every Key Screen from PRD §15 exists as a Mantine-composed React island wired into a Django-rendered page via `django-vite`. Branding and theming are operator-configurable from a real admin GUI. The component palette is covered in Storybook with a11y addon checks green. LinguiJS catalogs for en-US are complete for every translatable string surfaced by M0–M9. The release gates on a11y + i18n (full WCAG 2.2 AA pass, second locale, RTL verification, manual screen-reader runs) land in M10b.

## In scope

- PRD §15 UI/UX — every Key Screen, the Product Style guidelines, the Branding and Theming admin GUI, the React-island wiring, the Mantine + Radix-gap component palette, the LinguiJS extract/compile pipeline.
- PRD §03 Users and Personas — every persona's listed needs surfaced in the React-island UI.
- The remaining audit-event surfaces that need admin search/filter/export per PRD §14 — *shell only*, the full a11y + i18n hardening lands in M10b.

## Dependencies

- M0 React-island toolchain skeleton (Vite + django-vite + Mantine + LinguiJS + Storybook + Vitest + axe-core + ESLint).
- M1 baseline branding data model — M10a builds the admin GUI on top.
- M2–M9 — the underlying features. M10a is the polish + completeness pass on top of them.

## Deliverables

- Every PRD §15 Key Screen exists as a React island mounted on a Django-rendered page:
  - Student: Dashboard, Catalog, New deployment, Project detail, Deployment detail, Console view, Quota view, Sharing view, Script library.
  - Instructor: Course dashboard, Roster deployment, Stack wizard, Catalog publishing, Student deployment management, Script approval.
  - Admin: Provider inventory, Network offerings, Quota policies, Plugin management, Audit search (shell), Worker health, System settings, Branding and theme settings.
- Branding and Theming admin GUI per PRD §15:
  - Logo (light + dark variants), favicon, product name, login-screen banner/message.
  - Primary brand color + semantic accents with live preview.
  - Light/dark themes ship as default; per-user toggle + per-deployment default.
  - Email template branding.
  - Reset to defaults.
  - Custom CSS injection (admin-permission-gated + audit-logged).
  - Theme plugins (operator can install a theme package via the plugin lifecycle).
- Storybook coverage:
  - Every Mantine-composed component has at least one Storybook story.
  - Storybook a11y addon green on the component palette.
  - Storybook builds cleanly in CI.
- LinguiJS catalogs:
  - en-US catalog complete for every translatable string surfaced by M0–M9 (including plugin catalogs from M7a/M7b/M8/M9).
  - `react.po` extract + compile pass integrated into CI; catalog drift fails the build.
  - Plugin authors' React + Django catalogs merge cleanly on plugin enable.

## Acceptance criteria

- [ ] Every Key Screen listed in PRD §15 exists as a Mantine React island; each renders in dev and prod via `{% vite_asset %}`; each is navigable by keyboard alone at the component-level (full a11y pass lands in M10b).
- [ ] Every component used by a Key Screen has at least one Storybook story; Storybook a11y addon is green for the palette.
- [ ] An admin uploads a custom logo, sets a primary brand color, switches to dark theme, sees the change live without a page reload (live-preview); resets to defaults restores the shipped branding.
- [ ] A custom CSS injection by an admin lands in the rendered page; the action is audit-logged with the actor and the diff.
- [ ] A theme plugin installed via the plugin lifecycle shows up in the System Settings → Branding page and can be selected as the active theme.
- [ ] The catalog-drift CI gate refuses merges that add strings without `react.po` updates. (Full es-ES coverage is M10b scope; M10a verifies only the en-US pipeline + the drift gate.)
- [ ] Lingui extract + compile runs in CI; missing translations are visible on the translation-coverage admin page.
- [ ] TypeScript strict + Vitest + ESLint + Prettier + Storybook build all green for every Key Screen.

## Test layers

- **Tiny / unit**: Vitest + RTL for component logic; Zod schema round-trips for DRF response shapes; theme-color contrast computation for WCAG ratio checks; CSS-injection validator (rejects script tags, expression()-style attacks).
- **Contract**: the theme-plugin contract against a stub theme plugin; the translation-catalog merging logic from plugins.
- **Integration**: theme switch end-to-end with audit emission; translation-coverage admin command across en-US + a partial-coverage locale; custom-CSS injection storage + retrieval.
- **E2E**: every named user journey from PRD §17 §Testing that is backed by M1–M9 features renders end-to-end; full a11y + screen-reader runs land in M10b.

## Risks / open questions

- **Mantine ARIA coverage**: Mantine is generally WAI-ARIA-compliant but axe-core may flag components that need Radix fallbacks. M10a treats this as a normal Storybook a11y addon finding; M10b is the hardening pass.
- **Translation catalog scope for plugins**: M0 set up i18n scaffolding; M7a/M7b/M8/M9 plugins shipped their own catalogs; M10a verifies they all extract + compile cleanly. If a plugin's catalog conflicts with core strings, core wins; document.
- **Custom CSS security**: even with the validator, custom CSS can break the UI's layout (admin foot-gun). Surface a "reset CSS" button prominently.

## Out of scope (deferred to M10b)

- Full WCAG 2.2 AA pass (axe-core CI gate fails the build on any new violation across all E2E flows).
- Manual screen-reader runs (NVDA, VoiceOver, Orca).
- High-contrast theme variant beyond default light/dark.
- 200% browser zoom verification across every Key Screen.
- Second locale (es-ES) at 100% coverage.
- RTL verification via Arabic.
- Audit search admin UI full feature set (M10a ships the shell only).
- AAA on critical flows (deployment status, console launch, share-link acceptance).
