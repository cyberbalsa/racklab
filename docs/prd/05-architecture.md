# Architecture

RackLab uses a Django-centered control plane with durable async workers behind it.

## Core Components

- `web`: Django app serving HTML pages, AJAX fragment endpoints, API endpoints, admin screens, auth, catalog, RBAC, quota, networking, and audit views.
- `postgres`: system of record for users, projects, catalog, deployments, jobs, events, quotas, networks, scripts, approvals, tokens, audit records, and plugin configuration.
- `nats`: NATS JetStream for durable job dispatch, event fanout, and worker coordination.
- `provider-worker`: executes provider operations such as clone, snapshot, power, network attach, and console setup.
- `script-worker`: executes cloud-init rendering, console automation, network automation, and advanced scripts in isolated profiles.
- `console-worker`: manages console session setup and optional console automation flows.
- `scheduler-reconciler`: performs placement, quota reservation reconciliation, provider drift detection, expiration cleanup, and stuck job recovery.
- `notification-worker`: emits email or plugin-provided notifications.
- `artifact-storage`: filesystem or S3-compatible storage for verbose logs, screenshots, serial output, script artifacts, and job bundles.

## Control Flow

1. A user or API token requests an action.
2. Django validates authentication, RBAC, quota, policy, plugin capability, and approval state.
3. Django persists intent and audit records in PostgreSQL.
4. Django publishes a durable job message to NATS JetStream.
5. A worker consumes the message, executes the action, records state transitions, and emits progress events.
6. SSE and UI/API reads expose persisted progress to authorized users.
7. Reconciliation workers verify provider state and repair or mark drift.

## Architectural Principles

- Keep the core product coherent and deployable as a Django application.
- Use plugins for extensibility, not for unbounded internal indirection.
- Use NATS for async durability and scale, not as a replacement for database truth.
- Keep provider behavior idempotent so retries and reconciliation are safe.
- Isolate untrusted scripts from provider credentials and control-plane hosts.
- Make small installs simple and large installs horizontally scalable.
