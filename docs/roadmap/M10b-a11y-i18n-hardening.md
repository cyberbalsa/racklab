# M10b — a11y + i18n Hardening

**Status:** Not started.
**Estimated effort:** 2–3 weeks.
**Depends on:** M10a.
**Unblocks:** M13d. (M11b's dependency moved up to M10a — it needs the operator-shell components, not the a11y hardening pass — so the roadmap README's dependency graph wires `M10a → M11b` and `M10b → M13d`.)

## Goal

WCAG 2.2 AA is met across critical flows (AAA on deployment status, console launch, share-link acceptance per the PRD spec). Manual screen-reader runs are green. A second locale (es-ES) is at 100% coverage end-to-end. RTL is verified via Arabic. The high-contrast daisyUI theme variant exists. The audit-search admin UI (Filament panel) is feature-complete. Release-blocking a11y CI gates are green.

## In scope

- PRD §15 Accessibility — the full requirement set.
- PRD §15 Internationalization — the full requirement set.
- PRD §14 audit-search admin UI completeness (Filament panel).
- PRD §03 personas' accessibility needs surfaced end-to-end.

## Dependencies

- M10a — the Livewire 4 + daisyUI 5 component library + en-US catalog + `racklab:lang:check` CI gate must already exist.
- Pre-commit accessibility tooling: axe-core in Dusk, pa11y configured in CI per PRD §17.

## Deliverables

- Full WCAG 2.2 AA pass:
  - axe-core integrated into Dusk browser tests; CI gate fails the build on any new violation across all E2E flows.
  - pa11y runs on critical flows in CI.
  - Manual screen-reader run before promotion: NVDA, VoiceOver, Orca. Findings logged + fixed.
  - High-contrast daisyUI theme variant (`data-theme="rl-high-contrast"`) beyond the default light/dark.
  - 200% browser zoom passes without horizontal scrolling on the main flow.
  - Console pane confirmed accessible: text-mode fallback documented for screen-reader users, keyboard focus-release verified.
  - AAA on critical flows (deployment status, console launch, share-link acceptance) — 7:1 contrast on text in those surfaces.
- Full i18n hard-pass:
  - en-US catalog complete for every translatable string surfaced by M0–M10a (already complete from M10a; verify no drift via `racklab:lang:check`).
  - One additional locale (recommended: es-ES) reaches 100% coverage to prove the catalog pipeline end-to-end (Laravel `resources/lang/es/` populated).
  - RTL support verified by enabling Arabic in dev (catalog can be skeletal; goal is to verify layout flips correctly via Tailwind's `rtl:` variants and `dir="rtl"` on `<html>`).
  - Translation-coverage admin page (Filament panel widget) shows per-locale stats and missing/fuzzy strings sourced from `racklab:lang:check` output.
  - All plugin authors' translation files (`resources/lang/vendor/<plugin>/`) are merged on enable per the plugin contract.
  - **CLDR plural acknowledgement**: Laravel's `trans_choice()` is English-biased (two plural forms: `one` / `other`). Full CLDR plural categories (zero, one, two, few, many, other) require a community package layered on top of the Laravel translator — evaluate and adopt one before es-ES ships (Russian, Arabic, and other non-English locales have forms that `trans_choice()` silently drops). Document the chosen approach and add a note to the plugin authoring guide.
- Audit search admin UI: full filter / search / export Filament panel per PRD §14, with the right RBAC scoping for audit visibility.
- daisyUI component a11y audit: every component flagged by axe-core in Dusk or by pa11y is fixed at the Livewire + Blade markup level.

## Acceptance criteria

- [ ] axe-core CI gate (Dusk integration) is green on every E2E flow; no new violations introduced.
- [ ] pa11y is green on the critical flows: login, deployment create, console open, share-link issue.
- [ ] Manual NVDA + VoiceOver + Orca runs on the critical flows find no blocking screen-reader issues; non-blocking findings are tracked.
- [ ] The 200%-zoom test on every Key Screen succeeds with no horizontal scrolling on the main flow.
- [ ] AAA contrast verified on deployment status, console launch, and share-link acceptance.
- [ ] High-contrast daisyUI theme variant is available and passes the same axe-core gates.
- [ ] Switching the user's locale to es-ES makes every UI string render in Spanish; no English fallback shows for shipped surfaces; date/number/currency formatting respects the locale.
- [ ] Enabling Arabic flips the layout to RTL via Tailwind `rtl:` variants and `dir="rtl"`; icons that have direction (back/forward arrows) mirror; text is right-aligned by default.
- [ ] The translation-coverage Filament widget reports 100% for en-US and es-ES; intentionally removing a translation drops the percentage and the missing string is listed.
- [ ] CLDR plural handling is documented: either a community package is adopted and a Pest 4 test verifies Russian plurals round-trip correctly, or the limitation is explicitly acknowledged in the plugin authoring guide with a migration path noted.
- [ ] Audit search Filament panel supports filter / search / export with RBAC scoping; cross-tenant audit search uses the `multi_tenant` / `global` binding rules from PRD §19.

## Test layers

- **Unit (Pest 4 tiny)**: locale-resolution chain (per-user → `Accept-Language` → deployment default); RTL flip logic per component category; CLDR plural round-trips for each adopted locale.
- **Feature (Pest 4 integration)**: translation-coverage artisan command across en-US + es-ES + a partial-coverage locale; RTL layout-flip assertion via Livewire snapshot inspection; audit-export RBAC predicate.
- **Browser (Dusk)**: every named user journey from PRD §17 with axe-core running on every page via `$this->assertNoAxeViolations()`; pa11y runs on critical flows; manual screen-reader pass once before promotion.

## Risks / open questions

- **AAA on console UI**: AAA requires 7:1 contrast on text. The terminal in `@xterm/xterm` is configurable but defaults are AA. The high-contrast daisyUI theme should be the default in the console pane.
- **Manual screen-reader testing is slow**: budget 2–3 days per round and at least two rounds (mid-milestone + final). The findings drive UI changes.
- **RTL testing depth**: Arabic catalog can be skeletal because the goal is layout verification, not actual translation quality. But the layout must work for the entire UI, not just login.
- **CLDR plurals community package**: Laravel does not ship CLDR plural support. Candidate packages: `xiCO2k3/laravel-trans-str` or similar. Evaluate before es-ES ships; if none is satisfactory, document the constraint prominently and log an issue for post-v1 resolution.
- **daisyUI a11y gaps**: daisyUI 5 relies on correct HTML semantics from the author — there is no component-level ARIA injection. If axe-core surfaces systemic gaps, the fix is at the Blade/Livewire markup level, not at the CSS level. Budget one sprint for triage if the axe-core gate blocks the build.

## Out of scope (deferred)

- Mobile-responsive design beyond what Tailwind + daisyUI 5 provides by default — basic responsive works, but PRD §15 doesn't require a mobile-first design pass.
- Additional locales beyond en-US + es-ES (+ Arabic skeleton) — community-contributable post-M10b.
- A theme marketplace — operator installs theme plugins manually via the plugin lifecycle; a marketplace UI is post-v1.
- AAA across the whole UI — AAA is critical-flows-only per PRD §15.
