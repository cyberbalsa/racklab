# Container Operations

RackLab is container-first via Podman. There are two deployment profiles, both Podman-based, and both detailed in the Podman orchestration spec at `docs/superpowers/specs/2026-05-24-podman-orchestration.md`:

- **Baseline profile** — single host, Quadlets + systemd, no orchestrator. The default for tiny labs and small classrooms.
- **Scale profile** — multi-host, HashiCorp Nomad with the Podman driver. Horizon queue-depth-driven worker autoscaling. The path when a deployment outgrows one host.

Docker Compose / Podman Compose is **not** the deployment runtime. A `docker-compose.yml` is shipped as a development convenience (generated from the Quadlets at release time); operators do not run production deployments from Compose.

## Images And Services

RackLab ships first-class container images for:

- `web`
- `provider-worker`
- `script-worker`
- `console-worker`
- `scheduler-reconciler`
- `notification-worker`
- `redis`
- `reverb`
- `postgres`
- Optional local `object-storage` / MinIO
- (Scale only) `traefik`, `lego` cert agent, `nomad-server`, `nomad-client`, `prometheus`, `prometheus-redis-exporter`, `nomad-autoscaler`

Worker types use separate entrypoints so the orchestrator (Quadlets in Baseline, Nomad in Scale) can scale and isolate them independently.

## Configuration

Configuration supports:

- Environment variables.
- Mounted config files.
- Mounted secret files.
- External secret backends through plugins.
- Separate settings for development, test, and production.
- The active deployment profile selects the `WorkerRuntime` implementation at startup (`runtime.kind = "quadlet" | "nomad"`).

## Deployment Profiles

**Baseline (Quadlets):**

- All RackLab services declared as Quadlet units shipped in `deploy/quadlets/`.
- A `racklab-runtime.target` groups them for bulk start/stop.
- `podman auto-update` timer handles image refresh per unit.
- Suitable for one host. Multi-host Baseline is out of scope; if a deployment needs multi-host, use the Scale profile.

**Scale (Nomad with Podman driver):**

- Nomad servers (3 or 5, Raft) provisioned via Quadlets on dedicated or co-located hosts.
- Nomad clients (one per worker host) provisioned via Quadlets, with the Podman task driver pointed at the local Podman socket.
- RackLab services run as Nomad-scheduled jobs generated from `WorkerPoolSpec` declarations.
- Autoscaling on Horizon queue depth via Nomad Autoscaler + Prometheus (scraping Pulse metrics or a Horizon-status exporter), scaling **one pool first** (default: `provider-worker`) in v1 and expanding per-pool as policies are tuned.
- PostgreSQL, Redis, and the Nomad agent itself remain Quadlets.

## Operational Requirements

- Health checks for every container.
- Graceful shutdown for web and workers (worker SIGTERM handler stops pulling new messages, finishes the current one, exits 0).
- Queue drain support for workers — `WorkerRuntime.drain_replica` and `drain_pool` for both runtime implementations.
- Explicit migration command (`racklab migrate` for core; `racklab plugin migrate <name>` for plugins per the plugin migration lifecycle).
- Backup/restore documentation for PostgreSQL, Redis (queue snapshot), artifact storage, plugin config, and ACME state (Traefik `acme.json` and `lego`-managed PEMs). The `broadcast_event_log` and outbox tables are included in the Postgres backup.
- Upgrade documentation for both profiles (Quadlet image refresh + rollback; Nomad rolling deploy via `update` stanza with `auto_revert`).
- Development docker-compose file as a convenience.
- Production-oriented Quadlets shipped for Baseline.
- Production-oriented Nomad job templates (rendered from `WorkerPoolSpec`) shipped for Scale.

Kubernetes manifests or Helm can be added later but must not be required for normal operation.
