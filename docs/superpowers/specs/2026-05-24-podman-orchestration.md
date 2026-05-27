# Podman Orchestration: Baseline + Scale Profile

**Date:** 2026-05-24
**Status:** Decided.
**Decision owner:** Forrest Fuqua
**Scope:** How RackLab itself is deployed and how its worker pools are run and scaled. Provider backends (Proxmox today, others later) are out of scope here — see `docs/prd/12-proxmox-provider.md` and `docs/prd/13-plugin-system.md`.

## 1. Decision

1. **Podman is the runtime substrate for RackLab itself** — control plane and all worker pools. Provider backends (the VMs RackLab manages) stay on Proxmox.
2. **Two deployment profiles, both based on Podman**: a **Baseline profile** using Podman Quadlets + systemd (no orchestrator; Compose is a dev/example surface only, not the deployment runtime), and a **Scale profile** layering HashiCorp Nomad (BSL 1.1) with the Podman task driver on top of the same Podman hosts. The Baseline is the default; the Scale profile is opt-in via configuration.
3. **All worker dispatch goes through an internal `WorkerRuntime` interface.** The rest of RackLab — including every plugin under `docs/prd/13-plugin-system.md` — never imports Nomad or Podman APIs directly. There are two concrete implementations: `QuadletWorkerRuntime` (Baseline) and `NomadWorkerRuntime` (Scale).
4. **Horizon queue-depth is the primary autoscaling signal** in the Scale profile, sourced via Pulse metrics or a Horizon-status exporter and consumed by Nomad Autoscaler's built-in Prometheus APM plugin for the metric-driven core. The policy-and-discipline layer above the signal — including poison-job detection and per-job-age awareness — uses Horizon's `failed` queue + RackLab's own job state in Postgres, not Prometheus alone (§7).
5. **No cross-scheduler placement decisions**: Nomad schedules RackLab containers only. RackLab's *provider scheduler* (which decides where a VM lands on Proxmox per `docs/prd/11-quotas-scheduling-placement.md`) is a separate, RackLab-owned concern. The two may exchange read-only operational signals (e.g., provider health affects worker usefulness) but neither makes placement decisions on the other's behalf.

## 2. Context

The PRD specifies "Baseline uses Podman Quadlets; Scale uses Nomad + Podman; Compose is dev/example only" and "Kubernetes support is optional and must not drive baseline complexity" (`docs/prd/04-full-target-requirements.md`, `docs/prd/05-architecture.md`). RackLab must scale from 1-2 users on one host to thousands of users across many hosts. Worker pools are separated by responsibility (`provider-worker`, `script-worker`, `console-worker`, `scheduler-reconciler`, `notification-worker`) and must scale horizontally.

The user-stated requirements for orchestration:

- Central controller orchestrating multiple Podman hosts.
- All control services can have replicas (load-spreading and HA).
- Configurable per-host limits.
- Resource-aware placement (CPU/memory headroom).
- Queue-depth-driven autoscaling with hysteresis and warm-up.
- Scale from 1 host to N.
- "Fast easy deployments" — image refresh and rolling restart.
- RIT educational lab; student maintainer turnover.

These requirements only matter once a deployment is past the single-host case. Forcing them onto every RackLab install would violate the PRD's "K8s must not drive baseline complexity" intent. The two-profile design honors the floor and the ceiling separately.

## 3. The two profiles

### 3.1 Baseline profile — Quadlets

For tiny labs (1-2 users, single host), small classrooms, and any deployment that doesn't need queue-driven autoscaling.

- **Quadlets are the source of truth.** RackLab's services (web tier and worker pools) are declared as systemd Quadlet units shipped in `deploy/quadlets/`. The Baseline install script copies these to `/etc/containers/systemd/` and runs `systemctl daemon-reload`.
- A small `deploy/compose/docker-compose.yml` is shipped **as an example** for operators who want to inspect or run a one-off dev stack. It is generated from the Quadlets at release time and is not edited independently. The runtime contract is Quadlet.
- Replica counts are static per pool, configured per host. Scaling is manual: edit the Quadlet (or call `QuadletWorkerRuntime.set_replicas` through the admin UI), `systemctl daemon-reload`, `systemctl start racklab-script-worker@4` (template-instance pattern).
- No central controller. No autoscaler. No Prometheus required (though logs/metrics endpoints still emit; an operator can attach Prometheus if they want).
- Image refresh: the `podman auto-update` timer on each Quadlet detects newer images per the configured pull policy and rolls each unit. If the systemd unit fails its health check after update, Podman auto-update's rollback contract restores the previous image. This is image-driven, not health-driven — auto-update only acts when there's a newer image.
- This profile is the default. A vanilla `git clone && ./scripts/install.sh` produces the Baseline.

### 3.2 Scale profile — Nomad with Podman driver

For multi-host deployments and any deployment that needs queue-driven horizontal scaling.

- HashiCorp Nomad runs as a 3-server Raft cluster (or single server in `-dev` mode for evaluation). Each Podman host runs the Nomad agent. Workers are scheduled as Nomad jobs that target the Podman task driver.
- RackLab's services are still Podman containers; the difference is who decides how many of each run where. Nomad does that in the Scale profile.
- Nomad Autoscaler consumes Prometheus metrics from a Horizon-status exporter (specifically `racklab_horizon_queue_depth{queue}` per pool) and adjusts the `count` of each worker job per pool. Non-metric inputs (poison-job signals from the Horizon `failed` queue and per-job attempt counts on the Postgres `Job` row) feed scaling discipline at the policy layer rather than as raw APM metrics — see §7.
- Per-host limits and resource-aware placement come from Nomad's native scheduler (constraints, bin-packing, spread).
- The control plane (web tier) runs as a replicated Nomad job behind whichever ingress the operator chose (Caddy, Traefik, nginx — out of scope for this spec).
- **Postgres, Redis, and the Nomad agent itself remain Quadlets in v1.** This is an operational-simplicity call for v1, not a universal rule: Nomad can usefully supervise some infrastructure services, but for a first cut keeping the data-tier and infra-tier out of Nomad reduces the "what's where" surface a student maintainer must reason about. Postgres in particular should not be moved by Nomad without an HA Postgres story.

### 3.3 Choosing the profile

A single setting in `racklab.toml` (or env var) selects which `WorkerRuntime` implementation registers at startup: `runtime.kind = "quadlet"` (default) or `runtime.kind = "nomad"`. Switching profiles is a deliberate operational migration, not a runtime toggle. The migration story is part of the v1 documentation: install Nomad on existing hosts, register them, point the config at it, restart RackLab, drain the old Quadlet-managed workers.

## 4. The `WorkerRuntime` abstraction

`App\Domain\Runtime\WorkerRuntime` (PHP interface) exposes the contract that RackLab uses to spawn, scale, drain, and remove workers. The interface is split into two contracts: a narrow `PluginWorkerRuntime` interface exposed to plugins, and the full `WorkerRuntime` interface used by core RackLab, operators, and the autoscaler.

### 4.1 The contracts

```php
interface PluginWorkerRuntime
{
    /** Plugin-facing surface. Plugins can declare pools and observe state.
     *  They cannot scale, drain, or otherwise direct runtime resource allocation.
     */
    public function declarePool(WorkerPoolSpec $pool): void;
    public function removePool(string $poolName): void;
    /** @return list<ReplicaStatus> */
    public function listReplicas(string $poolName): array;
    public function runtimeCapabilities(): RuntimeCapabilities;
}

interface WorkerRuntime extends PluginWorkerRuntime
{
    /** Full contract used by RackLab core, operators, and the autoscaler. */
    public function setReplicas(string $poolName, int $count): ScaleResult;
    public function drainReplica(string $poolName, string $replicaId): void;
    public function drainPool(string $poolName): void;
    /** @return list<HostCapacity> */
    public function hostCapacity(): array;
    public function healthy(): RuntimeHealth;
}
```

Supporting types:

- `WorkerPoolSpec` — pool name, image reference, resource floor (CPU/memory), resource ceiling, environment, secrets references, optional placement hints (`prefer_host_class`, `forbid_host_class`), `min_replicas`, `max_replicas`, drain deadline, readiness/liveness contract.
- `ReplicaStatus` — replica id, pool name, host id, runtime state (`pending`/`running`/`draining`/`failed`), health (`healthy`/`degraded`/`unhealthy`), image digest, `started_at`, drain state. Used by the admin UI, the autoscaler (for warm-up arithmetic — §7.2), and incident handling.
- `RuntimeCapabilities` — supports_autoscale, supports_named_volumes, supports_rootless, supports_privileged, supports_devices, available_host_classes, max_replicas_per_pool_hint. Returned by `runtime_capabilities()` so plugins and core code can validate that what they're about to ask for is actually supported by the active runtime.
- `HostCapacity` — host id, host class, total CPU/memory, allocated CPU/memory, allocatable CPU/memory, replica counts per pool.
- `RuntimeHealth` — runtime kind, version, leader-elected (where applicable), known-good hosts vs degraded hosts.

### 4.2 Plugins declare, core scales

A plugin that contributes a new provider-worker variant calls `runtime.declare_pool(spec)` at install or boot. The plugin describes what a pool needs — image, resource floor, isolation requirement, host class. It does not call `set_replicas`. Scaling authority lives with:

- **The operator**, via the admin UI (Baseline and Scale).
- **The autoscaler** (Scale profile only), via the Nomad Autoscaler service.

This separation is the practical defense of §8's invariant: plugins cannot influence runtime scheduling regardless of what their author intends. The plugin contract test suite asserts that `set_replicas`, `drain_replica`, `drain_pool`, and `host_capacity` are not callable from plugin code.

### 4.3 Two implementations

- `QuadletWorkerRuntime` writes/updates Quadlet files via the systemd D-Bus, reads `systemctl show` for state, and reads `podman system df` + `/proc` for capacity. `set_replicas` for the Baseline profile is "ensure N template instances are enabled and started, disable the rest." `runtime_capabilities` reports `supports_autoscale = False` so callers can adjust UX accordingly.
- `NomadWorkerRuntime` talks to the Nomad API. `set_replicas` updates the job's `count`. `declare_pool` registers or updates a Nomad job spec generated from the `WorkerPoolSpec` (§6.2). `drain_replica` and `drain_pool` issue graceful evictions. `runtime_capabilities` reports `supports_autoscale = True`.

### 4.4 What's not in the interface

- **Provider-backend placement**: the provider scheduler operates on Proxmox nodes, not Podman hosts. `host_capacity` describes Podman hosts; nothing about Proxmox nodes touches `WorkerRuntime`.
- **The autoscaling policy itself**: that lives one layer up in RackLab's autoscaler service (§7), which calls `WorkerRuntime.set_replicas`.

## 5. Baseline profile details

### 5.1 Layout

Each Podman host running the Baseline profile carries:

- A small number of long-lived Quadlets: `racklab-web.container`, `racklab-postgres.container`, `racklab-redis.container`, `racklab-reverb.container`, and per-pool `racklab-<pool>@.container` template Quadlets.
- A `racklab-runtime.target` unit grouping them for bulk start/stop.
- Image-update enforcement via `podman auto-update` timer.

### 5.2 Operations

- **Install**: clone the repo, run `scripts/baseline-install.sh`. The script copies Quadlets to `/etc/containers/systemd/`, runs `systemctl daemon-reload`, enables and starts the units, and creates a minimal `racklab.toml`. Postgres and Redis initialize on first run.
- **Scale a pool**: `systemctl enable --now racklab-script-worker@3.service` (or use the RackLab admin UI which calls `QuadletWorkerRuntime.set_replicas`).
- **Image refresh**: `podman auto-update` runs on a timer; updates pull the configured tag and roll each unit. Rollback is automatic on failed health check post-update.

### 5.3 Limits

- Single host only. Multi-host Baseline is out of scope; if you need it, run the Scale profile.
- No queue-depth autoscaling. Scaling is manual.
- No HA control-plane replicas across hosts. Single-host means single-blast-radius.

## 6. Scale profile details

### 6.1 Layout

- Nomad servers (3 or 5, Raft) run as Quadlets on dedicated or co-located hosts. The user-facing `WorkerRuntime` is `NomadWorkerRuntime`.
- Nomad clients (one per worker host) run as Quadlets. Each client is configured with the Podman task driver pointing at the local Podman socket.
- Postgres remains a Quadlet (single instance for v1; HA Postgres is deferred).
- Redis 7 remains a Quadlet (or a Sentinel / Cluster Quadlet configuration for HA — same pattern).
- The Reverb daemon remains a Quadlet on the same host as Redis (or sidecar in the same systemd target).
- A Horizon-status exporter Quadlet emits `racklab_horizon_queue_depth{queue}` gauges per pool.
- Prometheus runs as a Quadlet (any operator-managed Prometheus also works).
- Nomad Autoscaler runs as a Quadlet (or as a Nomad job; the chicken-and-egg is tolerable because Autoscaler outages don't break the cluster, only the scaling decisions).

### 6.2 Worker job specs

RackLab uses generated Nomad job specs derived from `WorkerPoolSpec`. The flow:

1. The core RackLab process and each installed plugin call `runtime.declare_pool(spec)` at startup or on install.
2. `NomadWorkerRuntime.declare_pool` renders a job-spec template (`deploy/nomad/templates/worker-pool.nomad.tmpl`) using the spec, and submits or updates the job via the Nomad API.
3. The templates themselves are checked in and version-controlled. The set of declared pools at runtime is the union of core's built-in pools and plugin-declared pools.

Each rendered job spec includes:

- `task.driver = "podman"` with image, args, env, mounts (bind only — the Podman driver does not support named volumes).
- `resources` block with CPU and memory reservations + limits.
- `constraint` and `spread` blocks expressing the pool's placement hints (e.g., script-worker requires `host_class = "isolated"`).
- `update` block for rolling deploys: `max_parallel`, `health_check`, `auto_revert`.
- `scaling` block with `min`, `max`, and a Prometheus-source policy (only on pools where autoscaling is enabled; otherwise omitted and `count` is fixed).

### 6.3 Capacity awareness and per-host limits

Nomad's bin-packer handles resource-aware placement natively via CPU/memory reservations. `constraint` blocks include or exclude eligible nodes by attribute. `spread` blocks bias placement across host classes for diversity.

**Hard per-host caps on a specific pool** (e.g., "no more than 4 script-workers on any one host") are not directly expressible as a single Nomad primitive. The available approaches:

- **Host-class partitioning**: split hosts into classes (e.g., `script_worker_capacity = N`), and use `constraint` + `spread` to bound concentration. Coarse but operationally clear.
- **Per-host meta attributes**: each Nomad client advertises `meta.max_script_workers`; the pool's `constraint` selects only hosts where that attribute is set and high enough. Combined with `spread`, this gives soft caps.
- **Acceptance**: the v1 design treats per-host caps as a coarse policy expressed via host classes plus pool resource reservations. A "fine-grained per-host max replica count for pool X" feature is deferred. Operators who need it use host-class partitioning.

This is called out explicitly because the user requirement "configurable per-host limits" is real but the implementation is policy-by-host-class, not a magic per-pool-per-host number.

### 6.4 HA

The Nomad Raft cluster (3 servers) gives HA control of scheduling decisions. The Autoscaler can run with multiple replicas using Nomad-native leader election or a Consul lock; only the leader takes scaling actions. The RackLab control plane (web tier) is itself a Nomad job with `count >= 2` behind an operator-chosen ingress.

## 7. Horizon queue-depth autoscaling — discipline beyond "more backlog → more workers"

The naive autoscaling rule ("if backlog is high, add a worker") is wrong in several specific ways for RackLab. The Scale profile's autoscaler must enforce all of the following before being considered production-ready.

### 7.1 Signal interpretation

The two Horizon queue metrics that matter:

- **`racklab_horizon_queue_depth{queue, state="pending"}`** — jobs in the queue waiting to be processed. This is **deliverable backlog**.
- **`racklab_horizon_queue_depth{queue, state="processing"}`** — jobs currently being processed by a worker. This is **in-flight work**.

Both are emitted by the Horizon-status exporter Quadlet (reads `php artisan horizon:status --json` or the Horizon Metrics event data on each scrape interval). The exporter also emits `racklab_horizon_queue_depth{state="failed"}` for monitoring failed jobs and `racklab_horizon_queue_depth{state="total"}` for total.

Reading rules the policy must respect:

- **Saturation** is `processing >= max_processes` for the pool (the configured Horizon `processes` per supervisor). That is the "we need more workers" signal.
- **Backlog growth** is `pending` rising over time. With sufficient worker count, backlog drains; with insufficient count, backlog grows.
- **Worker idleness** is `processing < current_replicas * min_processes_per_replica` — workers are present but not all engaged with work.
- The right primary scaling primitive is "in-flight per replica": `processing / max(current_replicas, 1)`. High ratio approaching `max_processes` per replica → scale up. Low ratio with low `pending` for a sustained window → scale down.

Things that are **not** in Prometheus alone and must be sourced elsewhere:

- **`max_processes` per Horizon supervisor** is Horizon configuration (in `config/horizon.php`), not a metric. The autoscaler reads it from the RackLab config at startup and surfaces it as a static input to autoscaling policies (PromQL templating).
- **Per-job attempt count and age** are in the Postgres `jobs` table. Poison-job detection (§7.4) uses the Horizon `failed` queue and RackLab's own per-job state in Postgres, not Prometheus rates.

### 7.2 Policy parameters per pool

The Nomad Autoscaler `scaling` block exposes a small set of policy primitives. For each pool, the policy uses:

- `min` and `max` — replica bounds.
- `evaluation_interval` — how often the policy is re-evaluated.
- `cooldown` — silence after a scaling action before another can be considered.
- A `target-value` or `threshold` strategy whose query is a PromQL expression against the exporter counters.
- `max_scale_up_count` / `max_scale_down_count` — bounded steps per evaluation, to avoid thundering-herd reactions to transient spikes.

**Warm-up is not a built-in Nomad Autoscaler primitive.** Nomad Autoscaler's APM plugins (including the Prometheus one) query an APM source for a metric and use the returned value in their strategy; they do not pull from arbitrary RackLab APIs and cannot read `WorkerRuntime.list_replicas` directly. RackLab therefore **exports the warmed-replica count as a Prometheus metric** so PromQL can reference it.

The metrics emitter is part of the `web` tier (or a small standalone exporter Quadlet — implementation choice). It runs every `scrape_interval` (default 15s), reads replica state from `WorkerRuntime.list_replicas(pool)` for each declared pool, and emits:

```prometheus
# HELP racklab_worker_pool_replicas Replica counts by pool and state
# TYPE racklab_worker_pool_replicas gauge
racklab_worker_pool_replicas{pool="provider-worker",state="warming"} 1
racklab_worker_pool_replicas{pool="provider-worker",state="warmed"}  3
racklab_worker_pool_replicas{pool="provider-worker",state="draining"} 0
racklab_worker_pool_replicas{pool="provider-worker",state="total"}    4
```

A replica is `warmed` if `now - started_at >= warm_up_window` AND its health is `healthy`. `warming` is alive-but-not-yet-warmed. `draining` is in graceful drain. `total` is the sum across operational states.

The Nomad Autoscaler policy's PromQL then uses `racklab_worker_pool_replicas{pool="X",state="warmed"}` as the denominator when computing `processing / warmed_replicas`. New replicas count toward "we have enough workers" only once they cross the warm-up window — which is the intent of warm-up.

The warm-up window is per-pool (in the `WorkerPoolSpec`), defaulting to 30 seconds. Tuning is part of the per-pool policy work in §7.6.

Hysteresis emerges from `cooldown` + `query_window` (the time horizon the metric is averaged over) + asymmetric scale-up vs scale-down step sizes. The policy avoids both flapping and slow response to real load.

### 7.3 Graceful scale-down

A worker must finish its in-flight message before its container is terminated.

- Each worker has a SIGTERM handler that stops pulling new messages, finishes its current message up to the pool's drain deadline, and exits 0.
- The Nomad `task.kill_timeout` is set to the drain deadline plus a margin.
- `WorkerRuntime.drain_replica` triggers the SIGTERM-and-wait flow. `WorkerRuntime.set_replicas(name, n)` for `n < current` selects replicas to drain (least-loaded first) and uses `drain_replica` to retire them. The autoscaler does not see partial drain state as "scaled" until drain completes — `list_replicas` reflects `draining` state and the policy excludes draining replicas from the denominator.
- `WorkerRuntime.drain_pool` quiesces all replicas of a pool before any reduction. Used for host maintenance and Baseline↔Scale profile migrations.

### 7.4 Poison-job protection

The autoscaler does not infer poison jobs from Prometheus rates alone. It uses:

- **Horizon's `failed` queue** — Horizon moves jobs to the `failed` queue after they exceed `maxTries`. The `scheduler-reconciler` worker monitors this queue and cross-references with the Postgres `Job` ledger.
- **RackLab's per-job state in Postgres** — every Horizon job corresponds to a row in the universal `Job` ledger (PRD §19). For provider work the relevant subtype is `ProviderTask`; for script work it is `ScriptRun`; for console work `ConsoleSession`; etc. The reconciler tracks attempt counts on the parent `Job` row regardless of subtype; runaway attempt counts on a single job are the authoritative poison signal, agnostic to which pool consumed it.

When a poison job is detected, the autoscaler:

- Caps replicas for that pool at a configured `poison_cap` (default = `min_replicas`) until the poison job is dead-lettered or manually resolved.
- Emits an audit-logged alert.
- Does NOT scale up; adding workers does not fix a poison job.

### 7.5 Scale-to-zero

Scale-to-zero is **not supported** in v1. Reasons:

- Queue-depth counters can disappear or sit at zero when no workers exist, which complicates PromQL (`absent`, divide-by-zero handling) and creates an extra failure mode for a small benefit.
- Cold-start latency (Podman + PHP/Laravel Octane boot) is seconds-to-tens-of-seconds; for educational-lab UX, a cold worker pool when a student clicks "deploy" is poor.

Each pool has `min_replicas >= 1` in production. Scale-to-zero is a v2 concern at most.

### 7.6 v1 starting point

The Scale profile ships with autoscaling enabled on **one pool first** (default: `provider-worker`). Other pools start with static `count` and gain autoscaling per-pool as policies are tuned against real load. This is intentional: the discipline above is hard to get right in the abstract, and a single-pool starting point gives a learnable surface before all worker pools depend on the autoscaler.

## 8. Plugin coupling and the no-cross-scheduler invariant

### 8.1 Plugins never see Nomad

Every plugin under `docs/prd/13-plugin-system.md` declares its worker pool needs (if any) as a `WorkerPoolSpec` and calls `runtime.declare_pool(spec)` via the `PluginWorkerRuntime` Protocol (§4.1). No plugin imports Nomad or Podman APIs. The plugin contract version is independent of the runtime kind.

Larastan rules in the plugin SDK reject `use Nomad\`, `use Podman\`, and similar namespaces in plugin packages — `PluginWorkerRuntime` is the only path. Plugin contract tests assert the narrow interface.

### 8.2 Two schedulers, two scopes

Two scheduling decisions live in RackLab:

- **Container scheduling**: which Podman host runs which worker replica. Owned by `WorkerRuntime` (Quadlets or Nomad).
- **VM scheduling**: which Proxmox node hosts a student's VM. Owned by RackLab's provider scheduler (`docs/prd/11-quotas-scheduling-placement.md`).

**No cross-scheduler placement decisions.** Nomad does not pick a Proxmox node; the provider scheduler does not pick a Podman host. The two may exchange read-only signals — provider health affects worker usefulness, script-isolation policy maps to a Nomad host class — but those are operational inputs, not placement authority.

A future provider plugin that schedules its own VMs (e.g., libvirt) is treated the same way as Proxmox by the provider scheduler and is also unaware of Nomad. This is a hard invariant; violations get caught in code review and in the plugin contract tests.

## 9. Image lifecycle and deployments

Both profiles share an image lifecycle:

- **Build**: GitHub Actions builds and pushes container images on tag and on `main`. Images are versioned by git SHA and by semver tag.
- **Registry**: GitHub Container Registry (`ghcr.io/cyberbalsa/racklab/*`).
- **Refresh in Baseline**: the `podman auto-update` timer on each host runs at a configured interval. Per-Quadlet pull policy (`AutoUpdate=registry` for image-driven, `AutoUpdate=local` to disable) controls behavior. When auto-update detects a newer image for a unit, it pulls and replaces the container; if the unit fails its health check post-update, Podman's auto-update rollback restores the previous image. This is image-driven, not health-driven.
- **Refresh in Scale**: Nomad's `update` stanza handles rolling deploys with `max_parallel`, `health_check`, `auto_revert`. The operator runs `nomad job run racklab.nomad` (or invokes the RackLab admin UI) to deploy a new image version.

CI for the build pipeline is its own concern, layered onto the existing docs-CI in `.github/workflows/`. Not in scope for this spec.

## 10. License acceptance — Nomad BSL

Nomad is licensed under BSL 1.1 (since August 2023, IBM-owned via the HashiCorp acquisition closed February 27, 2025). For RackLab specifically:

- Internal use at an educational institution **appears permitted** under the current BSL Additional Use Grant: RackLab is not "offering Nomad as a competing managed service," which is what BSL forbids. Confirm with RIT counsel before production deployment.
- Each Nomad version carries its own 4-year change date. BSL terms auto-convert to MPL 2.0 four years after that version's release date — this is per-version, not a single calendar event. The license section in `racklab.toml` records which Nomad version is in use so the conversion date is auditable.
- **No mature OpenTofu-scale fork of Nomad exists** as of May 2026. Small community attempts exist; none with the production track record or ecosystem to substitute. The 4-year MPL conversion is the durable open-source escape hatch.
- If IBM ever tightens BSL terms, the response options are: fork from the last MPL-licensed Nomad release (v1.5.x, the final MPL version), migrate to a custom `WorkerRuntime` implementation, or accept the new terms. None is comfortable; all are possible but substantial.

**Recorded acceptance**: RackLab adopts Nomad under BSL 1.1 for the Scale profile, pending a brief legal sanity check with RIT before first production deployment. The Baseline profile carries no BSL dependency, so any deployment that can't accept BSL stays on Baseline and uses manual scaling.

## 11. Minimum viable v1

The Scale profile v1 ships with all of the following and nothing more:

- Nomad cluster (3 servers) provisioned via Quadlets on the operator's chosen hosts.
- Nomad clients (one per worker host) provisioned via Quadlets, with the Podman task driver installed and pointed at the local Podman socket.
- Postgres, Redis, and the Nomad agent itself remain Quadlets in v1.
- Generated Nomad job templates for `web`, `provider-worker`, `script-worker`, `console-worker`, `scheduler-reconciler`, `notification-worker`, rendered from `WorkerPoolSpec` at install/upgrade time.
- Per-pool `min_replicas` / `max_replicas`, CPU + memory reservations + limits, node-class constraints.
- Horizon-status exporter Quadlet + Prometheus + Nomad Autoscaler with the built-in Prometheus APM plugin.
- Autoscaling enabled on **one pool first** (`provider-worker`); other pools run at static `count` until policies are tuned.
- Cooldown, max scale-up step, max scale-down step, warm-up via PromQL on `started_at`, graceful drain via the `WorkerRuntime` drain methods.
- Horizon `failed` queue monitoring wired to the scheduler-reconciler; poison-job protection per §7.4.
- ACL, TLS, and secret-handling story documented and implemented for v1: Nomad ACLs enabled with role-scoped tokens, Nomad gossip + RPC TLS, Podman socket access restricted to the Nomad client user, Redis `requirepass` auth enabled, Prometheus scrape with bearer auth or mTLS, and secrets referenced by `WorkerPoolSpec` resolved from RackLab's secret backend (per PRD §18) before being injected into containers.
- Host-drain runbook documented for operator-initiated maintenance.

**Explicitly deferred**:

- Custom Horizon APM plugin (Prometheus path covers v1).
- Dynamic bin-packing UI.
- Multi-region Nomad.
- HA Postgres, HA Redis, autoscaled databases.
- Fine-grained per-host max-replica caps per pool (use host-class partitioning in v1).
- Any plugin-visible Nomad concepts.
- Scale-to-zero.
- Komodo or any operator UI layered on top of Nomad (re-evaluate post-v1).

The Baseline profile v1 ships with the Quadlet layout in §5, the `auto-update` timer, the `QuadletWorkerRuntime` implementation, and the install script. No autoscaler. No Prometheus. The Compose example file is generated from the Quadlets at release time and is not authoritative.

## 12. Open risks

- **Podman task driver maturity.** Nomad's Podman driver is community-maintained and separately installed (not built into Nomad core). Driver release cadence and Nomad-version compatibility are real operational inputs; pin driver versions and integration-test the pair.
- **Podman driver bind-mount-only.** No named-volume support. Walk through every worker pool's storage needs before committing; any pool that needs a Podman named volume needs a redesign.
- **Operational learning curve on Nomad.** At least one team member must own Nomad expertise. The Baseline profile is the mitigation for deployments without that ownership.
- **Two schedulers drift.** The "no cross-scheduler placement decisions" invariant is enforced by code review, not by type signature. A future test in the plugin-contract suite should explicitly assert this.
- **Image refresh footguns.** `podman auto-update` defaults to `:latest`; if RackLab ships `:latest`, every host updates on its own cadence. Use explicit tags + a controlled rollout from the registry side.
- **Postgres outside Nomad means Postgres HA is its own project.** v1 has single-instance Postgres. HA Postgres (Patroni, repmgr, etc.) is a future spec.
- **Horizon exporter cardinality.** Many worker pools with many queue names can produce a label explosion in Prometheus. Tune label policy before adding more pools to the autoscaler.
- **Plugin authors will try to import Podman or Nomad APIs.** The plugin SDK must make `PluginWorkerRuntime` the obvious and only path; Larastan rules should reject `use Podman\` / `use Nomad\` in plugin package namespaces.
- **ACL and secret handling complexity.** A v1 deployment that gets ACLs/TLS/secrets wrong is worse than one that runs Baseline. The Scale profile install path must produce a securely-configured cluster by default; "secure by default" is a hard requirement, not a documentation footnote.
- **BSL tail risk.** IBM tightens terms or stops 4-year MPL conversion. Mitigation is fork-from-last-MPL or runtime swap; both are substantial.

## 13. What I'd want to verify before committing

1. Smoke test the Baseline install on a fresh box. Time-to-first-deployment vs the time on a Scale install. Confirm the ratio is what we expect.
2. Spike `QuadletWorkerRuntime.set_replicas` and `NomadWorkerRuntime.set_replicas` against the same `WorkerPoolSpec`. Confirm the abstraction holds — no leak.
3. Validate the Horizon-status exporter actually emits `racklab_horizon_queue_depth` for each queue at the cardinality we'll use.
4. Implement the graceful drain handler on one worker pool end-to-end. Time a scale-down under load.
5. Run a poison-job protection test: inject a message that always fails; confirm the autoscaler caps replicas and emits an alert rather than scaling up.
6. Confirm Nomad's bind-mount-only Podman driver constraint doesn't block any pool we know we'll ship in v1.
7. Verify Quadlet auto-update rollback actually triggers on a synthetic health-check failure post-update.
8. Document RIT BSL acceptance (brief legal sanity check).
9. Implement the §11 ACL/TLS/secrets story on a 3-host Scale install and confirm "secure by default" from a fresh `scripts/scale-install.sh`.

## 14. Confidence

**High** on the two-profile shape — it matches the PRD's range and aligns Baseline operational simplicity with Scale operational capability.

**High** on the split `PluginWorkerRuntime` / `WorkerRuntime` abstraction — it's the design that keeps plugins from coupling to runtime details and removes scaling authority from plugin code.

**Medium** on the specific autoscaling parameters (per-pool thresholds, drain deadlines, max-step values). These are reasoned starting points to be tuned against real load.

**Medium** on the BSL acceptance — depends on RIT's reading of the license terms. Has a clean fallback (Baseline-only).

**Medium** on the v1 scope. It is intentionally narrow; the discipline is "ship the autoscaler for one queue first, then expand." If the team is tempted to enable autoscaling on all five pools day-one, expect oscillation and grief.
