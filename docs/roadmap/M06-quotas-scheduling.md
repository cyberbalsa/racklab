# M6 — Quotas + Scheduling

**Status:** Not started.
**Estimated effort:** 3–4 weeks.
**Depends on:** M3, M5a.
**Unblocks:** M5b, M7a, multi-user readiness.

## Goal

Multi-user RackLab is safe to use. Every deployment passes through quota reservation before Horizon dispatch; the scheduler picks a Proxmox node based on health + capacity + affinity; leases expire and clean up automatically. After M6, an instructor can confidently let a roster of students self-serve deployments without one student starving everyone else.

## In scope

- PRD §11 quotas, scheduling, and placement — every section.
- PRD §19 data model — `QuotaPolicy`, `QuotaLimit`, `QuotaReservation`, `QuotaUsage`, `QuotaEvent`.
- The placement signals listed in PRD §11 (provider health, node health, memory, CPU policy, storage, template locality, network availability, job pressure, tags, affinity/anti-affinity, reserved capacity, maintenance windows).

## Dependencies

- M3 Proxmox provider — placement reads from `ProviderCapacitySnapshot` rows produced by the inventory-discovery side of the provider plugin.
- M5a Networking — quota dimensions include provider-direct NICs and admin-published network-offering usage. M5b consumes the router/floating-IP/security-group dimensions after they exist.
- M2 deployment lifecycle — quota reservation is wired between RBAC validation and Horizon dispatch.

## Deliverables

- `racklab.quotas` Django app with the data model from PRD §19.
- The reservation model from PRD §11: validate → reserve → persist → publish → convert-to-usage on success → release on failure/cancellation/expiration.
- `racklab.scheduling` Django app: `Provider`, `ProviderCapacitySnapshot`, the scheduler service that ranks eligible targets.
- Scheduler service: reads provider plugin inventory + health + capacity + tags + reserved capacity + maintenance windows; picks a target; persists the decision on the deployment row.
- Periodic capacity-snapshot job in the `racklab-provider-proxmox` plugin: refreshes `ProviderCapacitySnapshot` rows on a schedule (default every 30s, configurable). Runs as a `ReconcilerTask` `Job` subtype.
- `Lease` model extensions: M2 ships the basic model + expiry sweep; M6 adds **quota-coupled lease limits** (max-lease-duration per scope, max-concurrent-leased-deployments per user/project/course) and the policy enforcement at deployment-create time.
- Admin UI: quota-policy management; per-scope (global / org / course / project / role / user / provider / network-offering / catalog-item / lease-window) limits.
- Student/instructor UI: quota usage indicator on the dashboard (clear quota and lease indicators per PRD §15 product style).
- Audit events from PRD §14: quota reservation, usage change, denial, override, expiration; deployment scheduling decision with the selected target and reasons.

## Acceptance criteria

- [ ] A student attempting to deploy when their CPU quota is exhausted gets a 422 with a clear quota-limit error and an audit event including the relevant limit.
- [ ] Two concurrent deployment requests against the same per-project quota are reserved correctly; one succeeds, the other is denied — no overcommitment.
- [ ] A deployment that fails after reservation releases its reservation; the same quota is immediately available to the next deployment.
- [ ] An instructor sets a course-level quota policy; students in the course see their effective quota (the most-restrictive cap) on the dashboard.
- [ ] The scheduler picks a Proxmox node with adequate memory + storage + template locality; the audit event records the candidate set, the chosen target, and the reasons.
- [ ] A node marked in maintenance mode is excluded from candidate selection; deployments don't land there.
- [ ] Anti-affinity is respected: two VMs in a stack with anti-affinity declared land on different nodes when capacity allows; refuses with a clear error when capacity doesn't.
- [ ] Lease expiration triggers cleanup; the deployment's resources are released; quota usage decrements; the audit event records the expiration cause.

## Test layers

- **Tiny / unit**: quota arithmetic (most-restrictive cap across scopes); reservation TTL logic; scheduler ranking algorithm against fixed inputs; lease-expiration cron-like clock.
- **Contract**: the placement Protocol against a fake provider with configurable capacity snapshots; the quota-reservation API against the M0 RBAC system; the lease-reaper against a fake clock.
- **Integration**: concurrent reservation under load (no overcommit); reservation released on deployment failure; lease expiry triggers cleanup end-to-end; provider-drift detection during scheduling.
- **E2E**: the M2/M3/M5a flow plus quota exhaustion (student hits the cap and sees the error); admin sets a course quota and a student in the course sees the new effective cap reflected on their dashboard.

## Risks / open questions

- **Quota dimensions multiply**: PRD §11 lists 14 quota dimensions. Pricing the full matrix is overkill for v1; pick the v1 default set (vCPU, memory, disk, concurrent deployments, lease duration, provider-direct NICs, private networks, floating IPs) and ship the rest as plugin-extensible. M6 defines the dimensions; M5b proves the writable networking ones.
- **Scheduler determinism vs spread**: predictable placement helps debugging, but always-pick-the-same-node creates hotspots. Default to weighted-random among the top-3 candidates; document.
- **Capacity-snapshot freshness**: 30-second poll cadence is a compromise between accuracy and Proxmox-API load. Under bursty deploys, the scheduler may pick a node that's just-filled. Mitigation: reserve capacity at scheduling time so subsequent decisions see the reservation; provider-side reconciliation catches drift.
- **Maintenance-window UX**: admins need a way to schedule maintenance that excludes a node for a window. The data model is clear; the admin UX is M10a territory but the underlying field lands in M6.

## Out of scope (deferred)

- Cost accounting / billing — not part of v1; quota usage is a count, not a cost.
- KubeVirt-aware scheduling — gated on KubeVirt provider plugin which is post-v1.
- Cross-cluster scheduling (RackLab federates over multiple PVE clusters and picks the best one) — v1 supports multiple clusters but the scheduler picks within a chosen cluster; cross-cluster ranking is M13d or later.
- ML-based predictive scheduling — explicitly out of scope.
