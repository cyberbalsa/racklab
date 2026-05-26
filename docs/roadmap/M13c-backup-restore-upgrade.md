# M13c — Backup, Restore + Upgrade Drills

**Status:** Not started.
**Estimated effort:** 2–3 weeks.
**Depends on:** M13a, M13b.
**Unblocks:** M13d.

## Goal

Operators can recover RackLab and upgrade it with evidence, not hope. M13c turns the point-in-time Baseline work from M2.5 into production-grade backup/restore and upgrade drills across both Baseline and Scale: Postgres, NATS, artifacts, plugin wheelhouse/config, secret backend, TLS state, and schema/plugin migrations.

## In scope

- PRD §16 backup/restore and upgrade operations.
- PRD §18 secret handling as it affects backup archives.
- M0.5 plugin persistence and SBOM/version provenance.
- M13a HA data tier and M13b observability signals used during drills.

## Dependencies

- M13a — stateful tier topology and failover behavior are known.
- M13b — backup/restore/upgrade drills emit metrics, traces, and alerts.
- M11a/M12 — Baseline `acme.json` and Scale `lego` PEM state exist.

## Deliverables

- Production backup command/runbook covering:
  - PostgreSQL base backup + WAL archiving for Scale; point-in-time dump for Baseline.
  - NATS JetStream stream snapshots.
  - artifact storage.
  - plugin wheelhouse + plugin lifecycle/config state.
  - secret-backend export/import path.
  - Traefik `acme.json` for Baseline and `lego` PEM directory for Scale.
- Restore command/runbook:
  - integrity verification: manifest version, sha256, schema version, dependency/plugin lock match.
  - restore to a fresh install.
  - partial restore where supported.
  - explicit refusal when plugin wheels or schema versions are incompatible.
- Upgrade runbooks:
  - Baseline image refresh via `podman auto-update` with rollback.
  - Scale upgrade via Nomad update stanzas with `auto_revert`.
  - core schema migration and plugin migration order.
  - downtime windows for non-zero-downtime schema changes.
- Drill automation: repeatable scripts for "backup → corrupt/delete state → restore → verify" and "upgrade → rollback → verify".
- CI/nightly jobs for restore and upgrade drills on self-hosted or nested-VM runners.
- Documentation for operator schedules: backup cadence, monthly restore rehearsal, upgrade rehearsal before production upgrade.

## Acceptance criteria

- [ ] Full restore drill: simulate disk loss on the Postgres host; restore from backup + WAL; verify zero data loss for committed deployments before the backup point.
- [ ] NATS stream restore drill: restore streams and consumer state; pending jobs are either resumed or explicitly reconciled without duplicate provider operations.
- [ ] Artifact storage restore drill: deployment artifacts, screenshots/logs, and audit exports referenced by DB rows resolve after restore.
- [ ] Plugin restore drill: enabled plugins and their pinned wheels/config restore on a fresh install without fetching from the internet.
- [ ] TLS state restore drill: Baseline `acme.json` and Scale `lego` PEM paths restore; HTTPS works after restart.
- [ ] Upgrade drill: deploy v1.0.0-like fixture → upgrade to v1.0.1-like fixture with schema/plugin migration → verify rollback path and documented downtime bounds.
- [ ] Backup archive with tampered sha256 or mismatched schema version is refused with a clear operator error.

## Test layers

- **Tiny / unit**: backup manifest validators; plugin-lock compatibility checks; archive integrity checks.
- **Contract**: backup source/sink Protocols against fake Postgres/NATS/artifact/secret backends.
- **Integration**: backup → destructive change → restore round trip with testcontainers Postgres + NATS; plugin wheelhouse restore.
- **E2E**: Baseline and Scale upgrade/rollback drills on operational runners.

## Risks / open questions

- **Backup encryption**: M2.5 defaulted to unencrypted archives with documented encryption recipes. M13c should decide whether encrypted-by-default is required for v1.
- **NATS snapshot fidelity**: verify consumer state and pending ack behavior, not just stream messages.
- **Plugin yanking**: restore must not depend on PyPI availability. Wheelhouse backup is mandatory.
- **Zero-downtime migration limits**: some schema changes require downtime. Document the policy rather than pretending every upgrade can be live.

## Out of scope (deferred)

- Multi-region disaster recovery.
- Continuous restore testing for every operator's external S3/NFS backend.
- Backup GUI — CLI/runbook is the v1 surface.
