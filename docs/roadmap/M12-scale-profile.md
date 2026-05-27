# M12 — Scale Profile (Nomad)

**Status:** Not started.
**Estimated effort:** 4–6 weeks.
**Depends on:** M3 (real Proxmox provider), M11a (Baseline TLS backend — the Scale-profile cert agent extends this), plus any worker pools whose Nomad scheduling M12 promises to handle. M12's scope is **the worker pools that exist at M12 implementation time**: M2 worker types (`provider-worker`, `scheduler-reconciler`, `notification-worker`) are guaranteed; `script-worker` (M7b) and `console-worker` post-SSH (M9) are scheduled if they've shipped, otherwise their Nomad job templates are added in their own milestones.
**Unblocks:** M13a.

## Goal

Multi-host RackLab deployments work. HashiCorp Nomad with the Podman driver schedules every RackLab container (FrankenPHP replicas, Horizon worker pools, Reverb daemon replicas with sticky sessions via Pusher cluster ID, per-job ephemeral containers as Nomad batch jobs) across multiple hosts. Horizon queue-depth-driven autoscaling adjusts worker replica counts via Nomad Autoscaler + Prometheus (scraping Pulse metrics or a Horizon-status exporter). The Scale profile's TLS is handled by Caddy's built-in TLS inside each FrankenPHP replica, or by a load balancer that terminates TLS upstream of the FrankenPHP fleet; ACME issuance profiles are configured against Caddy/FrankenPHP rather than a standalone Traefik instance. The same `WorkerRuntime` abstraction that powered M2's `QuadletWorkerRuntime` powers M12's `NomadWorkerRuntime`; no plugin code changes.

## In scope

- `docs/superpowers/specs/2026-05-24-podman-orchestration.md` Scale profile — every section.
- The `NomadWorkerRuntime` concrete implementation.
- The Horizon queue-depth autoscaling pipeline: Prometheus (scraping Pulse metrics or a Horizon-status exporter) + Nomad Autoscaler + the warmed-replica metric export.
- The Scale-profile TLS architecture: Caddy built-in TLS per FrankenPHP replica with coordinated ACME state, and/or load-balancer upstream TLS termination; ACME issuance profiles (manual cert upload, internal CA, ACME-DNS-01) configured via Caddy TLS directives.

## Dependencies

- M0 `WorkerRuntime` Protocol — M12 ships the second concrete implementation.
- M2 `QuadletWorkerRuntime` works for Baseline.
- M3 real Proxmox provider — the provider work isn't affected by the scheduler.
- M11a TLS/ACME integration — Scale extends it: Caddy TLS runs inside each FrankenPHP replica, or a load balancer terminates TLS upstream and routes to the FrankenPHP fleet.

## Deliverables

- `NomadWorkerRuntime` implementation: talks to the Nomad API, renders job specs from `WorkerPoolSpec` (using a checked-in `deploy/nomad/templates/worker-pool.nomad.tmpl`), implements `set_replicas` via `count` updates, `drain_replica` and `drain_pool` via graceful evictions.
- Nomad cluster Quadlets: `nomad-server` (3 servers, Raft), `nomad-client` (one per worker host), each provisioned via Quadlets. Nomad ACLs enabled with role-scoped tokens; gossip + RPC TLS enabled.
- `prometheus-redis-exporter` Quadlet on the Redis host (or sidecar in the Redis systemd target) + a Horizon-status exporter Quadlet emitting `racklab_horizon_queue_depth{queue}` gauges per pool.
- Prometheus Quadlet scraping the Redis exporter + the Horizon-status exporter + the new RackLab warmed-replica gauge.
- Nomad Autoscaler Quadlet with the Prometheus APM plugin enabled.
- The warmed-replica metric exporter in the RackLab web tier (or a small standalone exporter Quadlet): emits `racklab_worker_pool_replicas{pool, state}` gauges per the Podman spec §7.2.
- TLS in Scale mode: each FrankenPHP replica uses Caddy's built-in TLS with coordinated ACME state (shared storage or load-balancer termination). ACME issuance follows the profiles from the TLS spec — manual cert upload, internal CA, or ACME-DNS-01 — configured via Caddy TLS directives.
- Load-balancer config (HAProxy or nginx in front of the FrankenPHP fleet): routes traffic to live FrankenPHP replicas; Reverb replicas use sticky sessions via Pusher cluster ID for WebSocket continuity.
- FrankenPHP Nomad job spec includes TLS configuration; renewals are picked up per Caddy's file-watch / ACME renewal semantics without replica restart.
- `racklab.toml` setting `runtime.kind = "nomad"` selects the `NomadWorkerRuntime` at startup; switching from Baseline → Scale is a deliberate operational migration documented in the runbook.
- Autoscaling enabled on **one pool first** (`provider-worker`) per the spec; other pools run with static `count` until policies are tuned.
- Per-pool policy parameters (min, max, cooldown, evaluation interval, max-step) live in HCL alongside the Nomad job specs.
- Graceful scale-down: SIGTERM handler on each worker pool finishes the current message before exiting; Nomad `kill_timeout` exceeds the drain deadline.
- Poison-job protection: scheduler-reconciler monitors the Horizon `failed` queue + per-job `attempts` count on the `Job` row; sustained failure triggers replica-cap and audit alert via the reconciler's poison-detection logic.

## Acceptance criteria

- [ ] A **non-HA smoke install** (one Nomad-server host, one worker host; Postgres + Redis as Quadlets on the server host) completes from the runbook in under 45 minutes and proves the Nomad scheduling + autoscaler PromQL path. The smoke install is labeled non-HA explicitly because two hosts can't prove Raft quorum.
- [ ] A **production install** with the documented **3-node Nomad Raft cluster** + a separate worker host + Redis Sentinel or Cluster Quadlet completes from the runbook in under 90 minutes and proves the HA control-plane (server-failure drill: kill one Nomad server, scheduling continues; kill one Redis node, replication continues).
- [ ] A user deploys a VM (M2/M3 flow); the deployment lands on a Nomad-scheduled `provider-worker` job; Reverb WebSocket events stream live + replay endpoint serves missed events; the M2 acceptance criteria still pass.
- [ ] Generated a sustained queue-depth spike (200 simultaneous deployment requests); `provider-worker` autoscales up to `max_replicas` within the configured cooldown; backlog drains; replica count returns to baseline.
- [ ] The warmed-replica metric is observable in Prometheus and is being consumed by the Nomad Autoscaler policy as the denominator in `num_ack_pending / warmed_replicas`.
- [ ] A deliberately-introduced poison job (always fails) is detected via the Horizon `failed` queue and the per-job `attempts` count on the `Job` row; the affected pool is capped at `poison_cap` by the scheduler-reconciler; an audit alert fires; autoscaler refuses to add more replicas.
- [ ] Killing a worker mid-scale-up does not corrupt state; the reconciler resumes pending `Job` rows; no operation is silently re-submitted.
- [ ] The Scale-profile cert flow: Caddy ACME (or manual cert upload) provisions a LE/internal cert; all FrankenPHP replicas serve HTTPS within the documented convergence window; HTTPS works through any replica.
- [ ] TLS renewal completes without replica restart; certificate replacement is observable in Prometheus (cert-expiry metric updates).
- [ ] Reverb WebSocket sessions remain connected through a FrankenPHP replica restart; sticky-session routing via Pusher cluster ID is verified under load.
- [ ] Switching `runtime.kind = "nomad"` in `racklab.toml` and restarting RackLab routes new deployments through `NomadWorkerRuntime`; existing Quadlet-managed workers drain cleanly.

## Test layers

- **Tiny / unit**: PromQL warmup arithmetic (warmed-replica denominator handles zero / divide-by-zero); the Nomad job-spec template renderer (`WorkerPoolSpec` → HCL); the poison-job detection logic (Horizon failed-queue + `Job.attempts` threshold).
- **Contract**: `NomadWorkerRuntime` passes the same `WorkerRuntime` Protocol suite the `QuadletWorkerRuntime` does (so plugin code is identical across profiles); the warmed-replica exporter against a fake `list_replicas` source.
- **Integration**: testcontainers-style Nomad-in-docker + Podman driver + a real `provider-worker` job; sustained queue-depth load test verifying autoscale up + down; poison-job detection; failed worker recovery.
- **E2E** (nightly): full Scale-profile install on two hosts, run the M2/M3 deployment flow against it, verify autoscaling behavior, exercise Caddy TLS issuance against LE staging.

## Risks / open questions

- **Nomad Podman driver bind-mount-only**: PRD §17 already calls this out. Verify every worker pool's storage needs are bind-mount-expressible before promoting M12; any named-volume requirement is a redesign.
- **Nomad version vs Podman driver version**: pin known-good pairs. Document the upgrade-pair procedure.
- **Operational learning curve**: Nomad needs at least one operator who knows it. The Baseline profile remains the supported fallback for teams without that.
- **BSL license**: PRD records the BSL acceptance per the spec §10. Re-verify with RIT counsel before production use.
- **Autoscaling on more pools**: only `provider-worker` autoscales in v1. Adding `script-worker` autoscaling requires careful policy tuning because script-worker concurrency interacts with per-job container resource limits and Nomad task group constraints; budget that as a post-M12 iteration.
- **HA Postgres path**: deferred to M13a. The Scale profile uses single-instance Postgres on a Quadlet; that's a single point of failure. Document the failure mode prominently.

## Out of scope (deferred)

- HA Postgres (Patroni / repmgr) — M13a.
- **HA Redis failure drills** (controlled node-failure tests, automatic primary-promotion timing) — M13a. The v1 Scale profile **does** ship a Redis Sentinel / Cluster Quadlet configuration (it's HA at the broker level), but the operational drills + failover-timing SLOs land with the HA data-tier work in M13a.
- Multi-region Nomad — out of scope for v1.
- Cell-level fine-grained per-host max-replica caps per pool — host-class partitioning per the spec §6.3 is the v1 mechanism; finer caps are M13d or later.
- Scale-to-zero on worker pools — explicit non-goal per the spec §7.5.
- Plugin-visible Nomad concepts — explicit non-goal; the `PluginWorkerRuntime` abstraction stays narrow.
- Komodo or any operator UI on top of Nomad — re-evaluate post-v1.
