# M10b — a11y + i18n Hardening

**Status:** Not started.
**Estimated effort:** 2–3 weeks.
**Depends on:** M10a.
**Unblocks:** M13d. (M11b's dependency moved up to M10a — it needs the operator-shell components, not the a11y hardening pass — so the roadmap README's dependency graph wires `M10a → M11b` and `M10b → M13d`.)

## Goal

WCAG 2.2 AA is met across critical flows (AAA on deployment status, console launch, share-link acceptance per the PRD spec). Manual screen-reader runs are green. A second locale (es-ES) is at 100% coverage end-to-end. RTL is verified via Arabic. The high-contrast theme variant exists. The audit-search admin UI is feature-complete. Release-blocking a11y CI gates are green.

## In scope

- PRD §15 Accessibility — the full requirement set.
- PRD §15 Internationalization — the full requirement set.
- PRD §14 audit-search admin UI completeness.
- PRD §03 personas' accessibility needs surfaced end-to-end.

## Dependencies

- M10a — the component library + page shell + Storybook palette + en-US catalog must already exist.
- Pre-commit accessibility tooling: axe-core, pa11y, Storybook a11y addon configured in CI per PRD §17.

## Deliverables

- Full WCAG 2.2 AA pass:
  - axe-core CI gate fails the build on any new violation across all E2E flows.
  - pa11y runs on critical flows in CI.
  - Manual screen-reader run before promotion: NVDA, VoiceOver, Orca. Findings logged + fixed.
  - High-contrast theme variant beyond the default light/dark.
  - 200% browser zoom passes without horizontal scrolling on the main flow.
  - Console pane confirmed accessible: text-mode fallback documented for screen-reader users, keyboard focus-release verified.
  - AAA on critical flows (deployment status, console launch, share-link acceptance) — 7:1 contrast on text in those surfaces.
- Full i18n hard-pass:
  - en-US translation catalog complete for every translatable string surfaced by M0–M10a (already complete from M10a; verify no drift).
  - One additional locale (recommended: es-ES) reaches 100% coverage to prove the catalog pipeline end-to-end (Lingui + Django catalogs both populated).
  - RTL support verified by enabling Arabic in dev (catalog can be skeletal; goal is to verify layout flips correctly).
  - Translation-coverage admin page shows per-locale stats and missing/fuzzy strings.
  - All plugin authors' translation catalogs are merged on enable per the plugin contract.
- Audit search admin UI: full filter / search / export per PRD §14, with the right RBAC scoping for audit visibility.
- Mantine + Radix-gap audit: every component flagged by axe-core / pa11y / manual screen-reader testing is either fixed in place or swapped to a `@radix-ui/react-*` primitive styled with Mantine.

## Acceptance criteria

- [ ] axe-core CI gate is green on every E2E flow; no new violations introduced.
- [ ] pa11y is green on the critical flows: login, deployment create, console open, share-link issue.
- [ ] Manual NVDA + VoiceOver + Orca runs on the critical flows find no blocking screen-reader issues; non-blocking findings are tracked.
- [ ] The 200%-zoom test on every Key Screen succeeds with no horizontal scrolling on the main flow.
- [ ] AAA contrast verified on deployment status, console launch, and share-link acceptance.
- [ ] High-contrast theme variant is available and passes the same axe-core gates.
- [ ] Switching the user's locale to es-ES makes every UI string render in Spanish; no English fallback shows for shipped surfaces; date/number/currency formatting respects the locale.
- [ ] Enabling Arabic flips the layout to RTL; icons that have direction (back/forward arrows) mirror; text is right-aligned by default.
- [ ] The translation-coverage admin page reports 100% for en-US and es-ES; intentionally removing a translation drops the percentage and the missing string is listed.
- [ ] Audit search admin UI supports filter / search / export with RBAC scoping; cross-tenant audit search uses the `multi_tenant` / `global` binding rules from PRD §19.

## Test layers

- **Tiny / unit**: locale-resolution chain (per-user → Accept-Language → deployment default); RTL flip logic per component category.
- **Contract**: the translation-catalog merging logic from plugins; the audit-export RBAC predicate.
- **Integration**: translation-coverage admin command across en-US + es-ES + a partial-coverage locale; RTL layout-flip verification end-to-end.
- **E2E**: every named user journey from PRD §17 with axe-core + pa11y running on every page; manual screen-reader pass once before promotion.

## Risks / open questions

- **AAA on console UI**: AAA requires 7:1 contrast on text. The terminal in `@xterm/xterm` is configurable but defaults are AA. The high-contrast theme should be the default in the console pane.
- **Manual screen-reader testing is slow**: budget 2–3 days per round and at least two rounds (mid-milestone + final). The findings drive UI changes.
- **RTL testing depth**: Arabic catalog can be skeletal because the goal is layout verification, not actual translation quality. But the layout must work for the entire UI, not just login.
- **Mantine ARIA gaps**: if more than a handful of components need Radix fallbacks, consider whether the whole component library should switch to Chakra or React Aria. Document the criterion: if > 20% of components need fallback, the choice is wrong.

## Out of scope (deferred)

- Mobile-responsive design beyond what Mantine provides by default — basic responsive works, but PRD §15 doesn't require a mobile-first design pass.
- Additional locales beyond en-US + es-ES (+ Arabic skeleton) — community-contributable post-M10b.
- A theme marketplace — operator installs theme plugins manually via the plugin lifecycle; a marketplace UI is post-v1.
- AAA across the whole UI — AAA is critical-flows-only per PRD §15.
