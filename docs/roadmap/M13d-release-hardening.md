# M13d — Release Hardening

**Status:** Not started.
**Estimated effort:** 2–3 weeks.
**Depends on:** M10a, M10b, M13c.
**Unblocks:** v1 GA.

## Goal

RackLab is ready to tag v1.0. M13d closes the final quality gates: mutation testing on critical modules, a 24-hour soak, capacity planning with real load numbers, final security review, documentation completeness, and release checklist automation.

## In scope

- PRD §17 TDD discipline and mutation-testing requirements.
- PRD §14 and §18 final audit/security review.
- PRD §16 operational readiness.
- Release engineering: SBOMs, dependency audit, license policy, release notes, upgrade notes.

## Dependencies

- M10a — production-quality UI component library + page shells. M10b — full a11y + i18n hardening pass. Both M10a and M10b must be complete.
- M13c — backup/restore/upgrade drills are already passing.
- Every functional milestone M0–M12 has shipped its test layers.

## Deliverables

- Mutation testing via `pest --mutate` in nightly CI on critical modules:
  - RBAC enforcement.
  - quota reservation.
  - universal `Job` state machine.
  - Proxmox client task state machine.
  - SSH redaction pipeline.
  - autoscaler policy engine.
- 24-hour soak test against Scale profile with continuous deployment requests, console sessions, script runs, backup/restart/drain operations, and fault injection.
- Capacity planning docs with measured numbers:
  - per-host sizing for Baseline and Scale.
  - web/provider/script/console worker resource usage.
  - Redis/Postgres/artifact-storage sizing.
  - first bottleneck under load.
- Final security review:
  - `composer audit` + `roave/security-advisories` clean (no known-vulnerable Composer deps).
  - `enlightn/security-checker` clean (Laravel-specific security checks: HTTPS enforcement, CORS config, session security, queue serialization, etc.).
  - `npm audit` clean (no known-vulnerable JS deps).
  - Semgrep clean.
  - no audit-event catalog gaps.
  - no lint/type overrides in production code (`@phpstan-ignore`, `@psalm-suppress`, `// eslint-disable` all prohibited in non-generated code per PRD §17).
  - SBOM and license-policy outputs attached to release artifacts.
- Release checklist and automation:
  - version bump.
  - migration smoke.
  - SBOM generation.
  - image build/provenance.
  - codex review trigger: `codex exec --dangerously-bypass-approvals-and-sandbox "Review the release candidate. Goal: pre-release gate. Findings: correctness bugs, security issues, missing edge cases. Be terse, prioritize by severity."` runs as a required pre-release gate; P0/P1 findings block tagging; findings recorded in the release notes.
  - release notes.
  - upgrade notes.
  - rollback notes.
- Documentation completeness pass: installation, admin, student/instructor basics, plugin authoring, operations, troubleshooting.

## Acceptance criteria

- [ ] Mutation testing thresholds meet the configured per-module bar, with a default target of at least 80% mutation kill rate on critical modules.
- [ ] 24-hour Scale-profile soak completes with zero deadlocks, zero permanently stuck `Job` rows, zero unexpected data loss, and p99 deployment latency within the documented target.
- [ ] Load test reaches 10x expected v1 user count or documents the first bottleneck with a mitigation plan.
- [ ] Final security review passes: `composer audit`, `roave/security-advisories`, `enlightn/security-checker`, `npm audit`, Semgrep, SBOM/license policy, and audit-event coverage all clean.
- [ ] Release checklist can be run by a maintainer other than the author and produces a reproducible v1.0 release candidate.
- [ ] Upgrade notes from the previous release candidate are verified against M13c's upgrade drill.

## Test layers

- **Tiny / unit**: final gap-filling tests for any module below target coverage.
- **Contract**: release-checklist validators and SBOM/license-policy parsers.
- **Integration**: `pest --mutate` nightly CI job on critical modules; release-candidate build and install smoke.
- **E2E**: 24-hour soak; full release-candidate install + upgrade + rollback.
- **Non-functional**: load test and capacity-planning benchmark suite.

## Risks / open questions

- **Mutation wall-clock**: keep the mutation scope focused on critical modules so nightly CI finishes inside the operational window.
- **Soak fixture realism**: traffic replay must exercise mixed deployments, failures, console sessions, scripts, and backups; a single happy path is not enough.
- **Security review ownership**: decide who signs off on the final review and which findings block v1 versus become post-v1 issues.
- **Release reproducibility**: image and SBOM provenance should be deterministic enough that another maintainer can rebuild and compare.

## Out of scope (deferred)

- Multi-region HA/DR.
- Cost reporting or chargeback.
- Marketplace and plugin signing beyond the M0.5 provenance checks.
- Mobile-first redesign beyond the responsive baseline.
