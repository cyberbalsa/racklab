# M13a — HA Data Tier

**Status:** Not started.
**Estimated effort:** 2–3 weeks.
**Depends on:** M12.
**Unblocks:** M13b, M13c.

## Goal

The Scale profile has a tested high-availability story for stateful infrastructure. M13a promotes the data tier from "runs on a multi-host profile" to "survives controlled failures with documented recovery behavior": HA Postgres, Redis failure drills, stateful-tier runbooks, and client behavior under failover.

## In scope

- PRD §16 container operations — HA and stateful-tier operating requirements.
- PRD §14 observability inputs needed for data-tier health, but dashboards/alerts land in M13b.
- M12 Scale profile stateful components: Postgres, Redis (Horizon queue + cache + session + Reverb backplane), artifact storage mount assumptions, plugin Composer vendor dir/config storage.
- Laravel Octane state-leak hazards (spec §5 + §8): `max_requests` cap, reload-on-deploy discipline, tenant-context-leak contract tests.

## Dependencies

- M12 — Nomad/Podman Scale profile and Redis Sentinel / Cluster Quadlet configuration exist.
- M2.5 — backup/restore command shape and Baseline operational conventions exist.
- M0.5 — persistent plugin Composer vendor dir/config and state directories exist.

## Deliverables

- Supported HA Postgres pattern for v1, with one blessed implementation:
  - Patroni recommended unless implementation research selects another pattern.
  - Quadlets / Nomad job specs as appropriate for the selected profile.
  - connection-string and failover behavior documented for Laravel/web/workers.
- HA Postgres runbook: bootstrap, add/remove node, planned switchover, unplanned primary loss, rejoin failed node, credential rotation, backup interaction.
- Redis HA operational drills for the Sentinel / Cluster configuration introduced in M12:
  - node loss and recovery.
  - leader promotion (Sentinel failover or Cluster resharding).
  - queue + cache state verification after failover.
  - poison-job behavior during node loss.
- Data-tier client behavior:
  - web and worker processes survive transient DB/Redis disconnects within documented bounds.
  - worker jobs fail/retry explicitly rather than silently duplicating provider operations.
- Stateful storage requirements for artifact storage, plugin Composer vendor dir/config, secret backend, Caddy TLS state, and backups.
- Laravel Octane state-leak mitigations: `max_requests` cap configured per deployment profile; `SetTenantContextForOctane` middleware verified to reset on response (`terminate()`); Pest contract test that boots Octane, hits the same worker with consecutive requests for different tenants, and asserts no cross-tenant context bleed; Horizon workers exempt (they are short-lived pcntl-forked processes, not persistent Octane workers).
- Data-tier failure injection test harness used by M13b/M13c.
- Runbooks for known failure modes: Postgres primary loss, Redis node loss, Redis state corruption, shared storage unavailable, plugin Composer vendor dir unavailable.

## Acceptance criteria

- [ ] HA Postgres failover test: kill the primary; a standby promotes within the configured timeout; RackLab web/workers reconnect; deployment flow continues after the documented failover window.
- [ ] Planned Postgres switchover completes without data loss; active workers drain or retry in a documented way.
- [ ] Redis Sentinel / Cluster HA configuration: kill one node; replication continues; no job loss verified by cross-checking the Horizon queue depth against the Postgres `jobs` table.
- [ ] Redis leader promotion during active deployment jobs does not duplicate provider operations and leaves no `Job` row permanently stuck.
- [ ] Shared-storage outage for the plugin Composer vendor dir fails plugin operations closed while existing enabled plugins keep serving from already-loaded code where safe.
- [ ] Data-tier runbooks are rehearsed by someone who did not write them; missing command or ambiguous step is fixed before promotion.

## Test layers

- **Tiny / unit**: reconnect/backoff policy helpers; stateful-tier health-state reducer; failure-drill result parser.
- **Contract**: DB/Redis client wrappers against fake transient-disconnect servers; worker retry semantics under explicit disconnect classes.
- **Integration**: Patroni/repmgr selected stack failover; Redis node-loss and leader-promotion tests; worker/job behavior through data-tier disconnects.
- **E2E**: Scale deployment flow during controlled Postgres and Redis failures, with user-visible behavior documented.

## Risks / open questions

- **Postgres HA pattern choice**: pick one for v1. Supporting Patroni and repmgr at the same maturity level is too much; document migration paths to alternatives instead.
- **Shared storage availability**: HA data tier still depends on artifact/plugin/secret storage. M13a documents and tests failure modes; full storage replication strategy depends on operator environment.
- **Failover transparency expectations**: zero user-visible interruption may be unrealistic during DB primary loss. Define the tolerated window and assert it.
- **Redis HA is resilient but not magic**: state corruption and quorum loss still require operator action. Runbooks must say when automation stops.

## Out of scope (deferred)

- Prometheus dashboards, alert rules, and traces — M13b.
- Full backup/restore and upgrade drills — M13c.
- Mutation testing, 24-hour soak, and GA release gates — M13d.
- Multi-region replication — v2.
