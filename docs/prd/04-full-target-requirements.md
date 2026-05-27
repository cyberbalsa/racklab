# Full Target Requirements

RackLab is specified as a full target product. Implementation may be phased later, but these requirements describe the intended end state.

## Product Requirements

- Users can deploy Stacks from a catalog or from project-local Stack definitions; a single VM is represented as a one-component Stack.
- Catalog items are versioned and include provider capability requirements.
- Projects isolate deployments, networks, scripts, secrets, quota usage, and sharing policy.
- Every user gets a personal Project on first login or registration. Each Project has a reserved Default Stack for quick VM launches.
- Students can create resources when RBAC and quota allow it.
- Users with permission can create Stacks, add or remove VMs from deployed Stacks, rebuild an individual VM, or rebuild the full Stack.
- Instructors can deploy stacks for rosters and manage course-created deployments.
- Admins can configure providers, plugins, quotas, auth, networks, audit, and operations.
- Console views can show markdown instructions above, beside, and below the console.
- Users can restore from snapshots or deploy fresh from templates.
- Post-deployment automation can run through cloud-init, console automation, network automation, or advanced code.
- Public API access can automate every UI capability under the same authorization rules.
- Reverb WebSocket + `GET /api/v1/replay` provide live status for deployments, scripts, logs, workers, providers, approvals, quotas, and audits where appropriate.

## System Requirements

- PostgreSQL is the production database.
- Redis 7 + Laravel Horizon provides the job queue; the Postgres `broadcast_event_log` table and outbox pattern provide durable async event flow and replay.
- Workers are separated by responsibility and can scale horizontally.
- Provider actions are idempotent and reconciliation-friendly.
- Every external action passes through RBAC, quota, policy, and audit paths.
- The system supports tiny, small, department, and large-scale deployments.
- Container-first deployment via Podman. The **Baseline profile** uses Quadlets + systemd on a single host; the **Scale profile** uses HashiCorp Nomad with the Podman driver across multiple hosts. Docker / Podman Compose remains a development and example surface, not the deployment runtime.
- Kubernetes support is optional and must not drive baseline complexity.
