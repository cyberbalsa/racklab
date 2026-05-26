# M13a — HA Data Tier

**Status:** Not started.
**Estimated effort:** 2–3 weeks.
**Depends on:** M12.
**Unblocks:** M13b, M13c.

## Goal

The Scale profile has a tested high-availability story for stateful infrastructure. M13a promotes the data tier from "runs on a multi-host profile" to "survives controlled failures with documented recovery behavior": HA Postgres, NATS failure drills, stateful-tier runbooks, and client behavior under failover.

## In scope

- PRD §16 container operations — HA and stateful-tier operating requirements.
- PRD §14 observability inputs needed for data-tier health, but dashboards/alerts land in M13b.
- M12 Scale profile stateful components: Postgres, NATS JetStream, artifact storage mount assumptions, plugin wheelhouse/config storage.

## Dependencies

- M12 — Nomad/Podman Scale profile and 3-node NATS cluster exist.
- M2.5 — backup/restore command shape and Baseline operational conventions exist.
- M0.5 — persistent plugin wheelhouse/config and state directories exist.

## Deliverables

- Supported HA Postgres pattern for v1, with one blessed implementation:
  - Patroni recommended unless implementation research selects another pattern.
  - Quadlets / Nomad job specs as appropriate for the selected profile.
  - connection-string and failover behavior documented for Django/web/workers.
- HA Postgres runbook: bootstrap, add/remove node, planned switchover, unplanned primary loss, rejoin failed node, credential rotation, backup interaction.
- NATS JetStream operational drills for the 3-node cluster introduced in M12:
  - node loss and recovery.
  - leader move.
  - stream/consumer state verification.
  - poison-message behavior during node loss.
- Data-tier client behavior:
  - web and worker processes survive transient DB/NATS disconnects within documented bounds.
  - worker jobs fail/retry explicitly rather than silently duplicating provider operations.
- Stateful storage requirements for artifact storage, plugin wheelhouse/config, secret backend, Traefik/lego TLS state, and backups.
- Data-tier failure injection test harness used by M13b/M13c.
- Runbooks for known failure modes: Postgres primary loss, NATS node loss, NATS stream corruption, shared storage unavailable, plugin wheelhouse unavailable.

## Acceptance criteria

- [ ] HA Postgres failover test: kill the primary; a standby promotes within the configured timeout; RackLab web/workers reconnect; deployment flow continues after the documented failover window.
- [ ] Planned Postgres switchover completes without data loss; active workers drain or retry in a documented way.
- [ ] 3-node NATS JetStream cluster: kill one node; replication continues; no message loss verified by checksum-based messages through the cluster.
- [ ] NATS leader move during active deployment jobs does not duplicate provider operations and leaves no `Job` row permanently stuck.
- [ ] Shared-storage outage for the plugin wheelhouse fails plugin operations closed while existing enabled plugins keep serving from already-loaded code where safe.
- [ ] Data-tier runbooks are rehearsed by someone who did not write them; missing command or ambiguous step is fixed before promotion.

## Test layers

- **Tiny / unit**: reconnect/backoff policy helpers; stateful-tier health-state reducer; failure-drill result parser.
- **Contract**: DB/NATS client wrappers against fake transient-disconnect servers; worker retry semantics under explicit disconnect classes.
- **Integration**: Patroni/repmgr selected stack failover; NATS node-loss and leader-move tests; worker/job behavior through data-tier disconnects.
- **E2E**: Scale deployment flow during controlled Postgres and NATS failures, with user-visible behavior documented.

## Risks / open questions

- **Postgres HA pattern choice**: pick one for v1. Supporting Patroni and repmgr at the same maturity level is too much; document migration paths to alternatives instead.
- **Shared storage availability**: HA data tier still depends on artifact/plugin/secret storage. M13a documents and tests failure modes; full storage replication strategy depends on operator environment.
- **Failover transparency expectations**: zero user-visible interruption may be unrealistic during DB primary loss. Define the tolerated window and assert it.
- **NATS cluster is HA but not magic**: stream corruption and quorum loss still require operator action. Runbooks must say when automation stops.

## Out of scope (deferred)

- Prometheus dashboards, alert rules, and traces — M13b.
- Full backup/restore and upgrade drills — M13c.
- Mutation testing, 24-hour soak, and GA release gates — M13d.
- Multi-region replication — v2.
