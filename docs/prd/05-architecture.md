# Architecture

RackLab uses a PHP/Laravel control plane with durable async workers behind it.

> **Note:** Implementation detail and rationale for the stack choices below live in `docs/superpowers/specs/2026-05-26-laravel-redesign.md`, which is the source of truth for *how* RackLab is built. This section captures the topology and architectural principles; the spec covers container manifests, network modes, hookspec semantics, CI gates, and the per-component versions.

## Core Components

- `web`: Laravel application (FrankenPHP + Octane workers) serving Livewire 4 pages, Filament 5 admin panel, JSON API endpoints, auth, catalog, RBAC, quota, networking, and audit views.
- `postgres`: system of record for users, projects, catalog, deployments, jobs, events, quotas, networks, scripts, approvals, tokens, audit records, plugin configuration, `broadcast_event_log` (real-time replay log), and `audit_events` hash chain.
- `redis`: queues (Horizon), cache, session, and Reverb WebSocket backplane.
- `reverb`: Laravel Reverb daemon (MIT, Pusher protocol, separate process) for real-time WebSocket delivery.
- `horizon-workers`: Horizon worker pools (separate processes, `pcntl`/`posix`, tagged per queue) that execute provider operations, script orchestration, console session setup, scheduling/reconciliation, and notifications.
- `job-containers`: per-job ephemeral Podman containers (`racklab/ansible-runner:v1`, `racklab/user-script:v1`, `racklab/console-script:v1`) for cloud-init rendering, console automation, network automation, and advanced scripts in isolation.
- `artifact-storage`: filesystem or S3-compatible storage (via Flysystem + plugin family) for verbose logs, screenshots, serial output, script artifacts, and job bundles.

## Process Topology

```text
┌─────────────────────────────────────────────────────────────┐
│  FrankenPHP (Caddy + embedded PHP, single static binary)    │
│  ├─ Laravel Octane worker mode (app booted in memory)       │
│  │  ├─ HTTP request handling (Livewire 4 components,        │
│  │  │  Filament 5 panels, JSON API, Sanctum auth)           │
│  │  └─ Broadcast publisher → Reverb                         │
│  └─ Caddy TLS (automatic ACME, HTTP/2 + HTTP/3 + 103 hints) │
└─────────────────────────────────────────────────────────────┘
                            │
       ┌────────────────────┼────────────────────────────────┐
       ▼                    ▼                                ▼
┌──────────────────────┐  ┌─────────────────────┐  ┌──────────────────────────┐
│  Postgres 16         │  │  Redis 7            │  │  Reverb daemon (MIT)     │
│  ├─ row-level tenant │  │  ├─ Queues (Horizon)│  │  ├─ Pusher protocol      │
│  │  isolation        │  │  ├─ Cache           │  │  ├─ WebSocket listener   │
│  ├─ broadcast_event_ │  │  ├─ Session         │  │  └─ Behind Caddy upstream│
│  │  log (replay log) │  │  └─ Reverb backplane│  │     (or own TLS listener)│
│  └─ audit_events     │  │                     │  └──────────────────────────┘
│     hash chain       │  │                     │
└──────────────────────┘  └─────────────────────┘
       │                    │
       └────────────┬───────┘
                    ▼
        ┌──────────────────────────┐
        │  Horizon workers         │
        │  (separate processes,    │
        │   pcntl/posix, tagged    │
        │   per queue)             │
        └──────────────────────────┘
                    │
                    ▼
        ┌──────────────────────────────────┐
        │  Per-job ephemeral Podman/Docker │
        │  containers                      │
        │  ├─ racklab/ansible-runner:v1    │
        │  ├─ racklab/user-script:v1       │
        │  └─ racklab/console-script:v1    │
        └──────────────────────────────────┘
                    │
                    ▼
        ┌─────────────────────┐
        │  Proxmox VE cluster │
        │  (REST API via      │
        │  Guzzle from app +  │
        │  trusted workers    │
        │  only; console-     │
        │  script containers  │
        │  reach only the     │
        │  ProviderConsole-   │
        │  Proxy unix socket) │
        └─────────────────────┘
```

**Deployment profiles:**

- **Baseline (1–~50 users)**: single host. FrankenPHP, Postgres, Redis, Reverb daemon, Horizon workers, container-runtime all on one box. Systemd units (Quadlets) for non-PHP pieces. Backup is a Postgres dump + Redis snapshot + filesystem tar.
- **Scale (50+ users, multi-host)**: Nomad with the Podman driver schedules everything — FrankenPHP replicas, Horizon worker pools, Reverb daemon replicas (sticky sessions via Pusher cluster ID), per-job containers as Nomad batch jobs, Postgres + Redis as managed services or Nomad-scheduled.

## Control Flow

1. A user or API token requests an action.
2. Laravel validates authentication (Sanctum session/PAT or Track A JWT), RBAC, quota, policy, plugin capability, and approval state.
3. Laravel persists intent and audit records in PostgreSQL.
4. Laravel dispatches a durable job to the Redis-backed Horizon queue.
5. A Horizon worker consumes the job, executes the action (spawning an ephemeral Podman container if needed), records state transitions, and broadcasts progress events via Reverb.
6. Reverb pushes real-time progress to authorized clients; on reconnect, clients drain missed events via `GET /api/v1/replay?channel=…&since=<ULID>` backed by `broadcast_event_log` (matches PRD §07 Last-Event-ID semantics, but the wire endpoint is an HTTP replay, not an SSE `Last-Event-ID` header).
7. Reconciliation workers in Horizon verify provider state and repair or mark drift.

## Architectural Principles

- Keep the core product coherent and deployable as a single Laravel application with FrankenPHP.
- Use plugins (Composer packages + ServiceProvider + typed hookspec event bus) for extensibility, not for unbounded internal indirection.
- Use the Redis queue (Horizon) for async durability and scale; Postgres is the system of record for all durable state.
- Keep provider behavior idempotent so retries and reconciliation are safe.
- Isolate untrusted scripts from provider credentials and control-plane hosts via per-job ephemeral containers.
- Make small installs simple (Baseline single-host) and large installs horizontally scalable (Scale multi-host via Nomad).
