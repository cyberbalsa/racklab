# M12 — Scale Profile (Nomad)

**Status:** Not started.
**Estimated effort:** 4–6 weeks.
**Depends on:** M3 (real Proxmox provider), M11a (Baseline TLS backend — the Scale-profile cert agent extends this), plus any worker pools whose Nomad scheduling M12 promises to handle. M12's scope is **the worker pools that exist at M12 implementation time**: M2 worker types (`provider-worker`, `scheduler-reconciler`, `notification-worker`) are guaranteed; `script-worker` (M7b) and `console-worker` post-SSH (M9) are scheduled if they've shipped, otherwise their Nomad job templates are added in their own milestones.
**Unblocks:** M13a.

## Goal

Multi-host RackLab deployments work. HashiCorp Nomad with the Podman driver schedules every RackLab container (`web`, all worker pools) across multiple hosts. NATS-queue-depth-driven autoscaling adjusts worker replica counts via Nomad Autoscaler + Prometheus + the NATS exporter. The Scale profile's TLS uses an external `lego` cert agent writing PEMs to a shared volume that Traefik consumes via `tls.certificates` (sidesteps Traefik OSS's multi-instance ACME restriction). The same `WorkerRuntime` abstraction that powered M2's `QuadletWorkerRuntime` powers M12's `NomadWorkerRuntime`; no plugin code changes.

## In scope

- `docs/superpowers/specs/2026-05-24-podman-orchestration.md` Scale profile — every section.
- The `NomadWorkerRuntime` concrete implementation.
- The NATS-queue-depth autoscaling pipeline: `prometheus-nats-exporter` + Prometheus + Nomad Autoscaler + the warmed-replica metric export.
- The Scale-profile ACME architecture from the TLS spec §6.1 — external `lego` cert agent + shared volume + load-balancer HTTP-01 challenge routing.

## Dependencies

- M0 `WorkerRuntime` Protocol — M12 ships the second concrete implementation.
- M2 `QuadletWorkerRuntime` works for Baseline.
- M3 real Proxmox provider — the provider work isn't affected by the scheduler.
- M11a Traefik integration — Scale extends it with the `tls.certificates` static-cert mode and the cert-agent design.

## Deliverables

- `NomadWorkerRuntime` implementation: talks to the Nomad API, renders job specs from `WorkerPoolSpec` (using a checked-in `deploy/nomad/templates/worker-pool.nomad.tmpl`), implements `set_replicas` via `count` updates, `drain_replica` and `drain_pool` via graceful evictions.
- Nomad cluster Quadlets: `nomad-server` (3 servers, Raft), `nomad-client` (one per worker host), each provisioned via Quadlets. Nomad ACLs enabled with role-scoped tokens; gossip + RPC TLS enabled.
- `prometheus-nats-exporter` Quadlet on the NATS host (or sidecar in the NATS systemd target).
- Prometheus Quadlet scraping the NATS exporter + the new RackLab warmed-replica gauge.
- Nomad Autoscaler Quadlet with the Prometheus APM plugin enabled.
- The warmed-replica metric exporter in the RackLab web tier (or a small standalone exporter Quadlet): emits `racklab_worker_pool_replicas{pool, state}` gauges per the Podman spec §7.2.
- `lego` cert agent Quadlet on a dedicated cert-management host: ACME HTTP-01 challenge handler on port 80, writes PEMs to a shared volume mounted into all Traefik replicas.
- Load-balancer config (HAProxy or nginx in front of Traefik) that routes `/.well-known/acme-challenge/*` to the cert-agent host and everything else to the Traefik fleet.
- Traefik dynamic config in Scale mode references `tls.certificates` entries pointing at the shared PEM paths; file-watch picks up renewals.
- `racklab.toml` setting `runtime.kind = "nomad"` selects the `NomadWorkerRuntime` at startup; switching from Baseline → Scale is a deliberate operational migration documented in the runbook.
- Autoscaling enabled on **one pool first** (`provider-worker`) per the spec; other pools run with static `count` until policies are tuned.
- Per-pool policy parameters (min, max, cooldown, evaluation interval, max-step) live in HCL alongside the Nomad job specs.
- Graceful scale-down: SIGTERM handler on each worker pool finishes the current message before exiting; Nomad `kill_timeout` exceeds the drain deadline.
- Poison-job protection: scheduler-reconciler subscribes to `$JS.EVENT.ADVISORY.CONSUMER.MAX_DELIVERIES.>`; sustained redelivery caps replicas and emits an alert.

## Acceptance criteria

- [ ] A **non-HA smoke install** (one Nomad-server host, one worker host; Postgres + NATS as Quadlets on the server host) completes from the runbook in under 45 minutes and proves the Nomad scheduling + autoscaler PromQL path. The smoke install is labeled non-HA explicitly because two hosts can't prove Raft quorum.
- [ ] A **production install** with the documented **3-node Nomad Raft cluster** + a separate worker host + the 3-node NATS Quadlet cluster completes from the runbook in under 90 minutes and proves the HA control-plane (server-failure drill: kill one Nomad server, scheduling continues; kill one NATS node, replication continues).
- [ ] A user deploys a VM (M2/M3 flow); the deployment lands on a Nomad-scheduled `provider-worker` job; SSE events stream live; the M2 acceptance criteria still pass.
- [ ] Generated a sustained queue-depth spike (200 simultaneous deployment requests); `provider-worker` autoscales up to `max_replicas` within the configured cooldown; backlog drains; replica count returns to baseline.
- [ ] The warmed-replica metric is observable in Prometheus and is being consumed by the Nomad Autoscaler policy as the denominator in `num_ack_pending / warmed_replicas`.
- [ ] A deliberately-introduced poison message (always fails) is detected via the NATS advisory; the affected pool is capped at `poison_cap`; an audit alert fires; autoscaler refuses to add more replicas.
- [ ] Killing a worker mid-scale-up does not corrupt state; the reconciler resumes pending `Job` rows; no operation is silently re-submitted.
- [ ] The Scale-profile cert flow: `lego` issues a real LE cert; both Traefik replicas pick up the PEM via file-watch within ~5 seconds; HTTPS works through either replica.
- [ ] Renewal via `lego` cron triggers a fresh PEM; Traefik replicas pick it up without restart.
- [ ] An attempt to share `acme.json` directly across Traefik replicas (without the cert-agent path) fails the design review — verified by the documented runbook.
- [ ] Switching `runtime.kind = "nomad"` in `racklab.toml` and restarting RackLab routes new deployments through `NomadWorkerRuntime`; existing Quadlet-managed workers drain cleanly.

## Test layers

- **Tiny / unit**: PromQL warmup arithmetic (warmed-replica denominator handles zero / divide-by-zero); the Nomad job-spec template renderer (`WorkerPoolSpec` → HCL); the poison-job advisory event parser.
- **Contract**: `NomadWorkerRuntime` passes the same `WorkerRuntime` Protocol suite the `QuadletWorkerRuntime` does (so plugin code is identical across profiles); the warmed-replica exporter against a fake `list_replicas` source.
- **Integration**: testcontainers-style Nomad-in-docker + Podman driver + a real `provider-worker` job; sustained queue-depth load test verifying autoscale up + down; poison-job detection; failed worker recovery.
- **E2E** (nightly): full Scale-profile install on two hosts, run the M2/M3 deployment flow against it, verify autoscaling behavior, exercise the `lego` cert agent against LE staging.

## Risks / open questions

- **Nomad Podman driver bind-mount-only**: PRD §17 already calls this out. Verify every worker pool's storage needs are bind-mount-expressible before promoting M12; any named-volume requirement is a redesign.
- **Nomad version vs Podman driver version**: pin known-good pairs. Document the upgrade-pair procedure.
- **Operational learning curve**: Nomad needs at least one operator who knows it. The Baseline profile remains the supported fallback for teams without that.
- **BSL license**: PRD records the BSL acceptance per the spec §10. Re-verify with RIT counsel before production use.
- **Autoscaling on more pools**: only `provider-worker` autoscales in v1. Adding `script-worker` autoscaling requires careful policy tuning because script-worker concurrency interacts with nsjail process limits; budget that as a post-M12 iteration.
- **HA Postgres path**: deferred to M13a. The Scale profile uses single-instance Postgres on a Quadlet; that's a single point of failure. Document the failure mode prominently.

## Out of scope (deferred)

- HA Postgres (Patroni / repmgr) — M13a.
- **HA NATS failure drills** (controlled node-failure tests, automatic primary-promotion timing) — M13a. The v1 Scale profile **does** ship a 3-node NATS Quadlet cluster (it's HA at the broker level), but the operational drills + failover-timing SLOs land with the HA data-tier work in M13a.
- Multi-region Nomad — out of scope for v1.
- Cell-level fine-grained per-host max-replica caps per pool — host-class partitioning per the spec §6.3 is the v1 mechanism; finer caps are M13d or later.
- Scale-to-zero on worker pools — explicit non-goal per the spec §7.5.
- Plugin-visible Nomad concepts — explicit non-goal; the `PluginWorkerRuntime` abstraction stays narrow.
- Komodo or any operator UI on top of Nomad — re-evaluate post-v1.
