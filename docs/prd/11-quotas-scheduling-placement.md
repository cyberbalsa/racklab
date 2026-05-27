# Quotas, Scheduling, And Placement

## Quotas

RackLab quotas cover:

- vCPU.
- Memory.
- Disk.
- Snapshots.
- Networks.
- Subnets.
- Ports.
- Routers/NAT gateways.
- Floating/public IPs.
- VPN endpoint public/service IPs.
- VPN endpoint UDP ports.
- VPN client profiles.
- Provider-direct NICs.
- Security group rules.
- Script runtime.
- Script concurrency.
- Concurrent deployments.
- Lease duration.

Quota scopes:

- Global.
- Organization.
- Course.
- Project.
- Role.
- User.
- Provider.
- Provider cluster/server.
- Network offering.
- Catalog item.
- Lease window.

## Reservation Model

Quota enforcement uses reservation before deployment.

Flow:

1. Validate request policy.
2. Reserve quota and provider capacity.
3. Persist reservation.
4. Publish deployment job.
5. Convert reservation to usage when resources become active.
6. Release reservation on failure, cancellation, expiration, or cleanup.

This prevents concurrent requests from overcommitting the same quota.

## Scheduling

The scheduler chooses eligible provider targets and records its decision.

Signals:

- Provider health.
- Node health.
- Available memory.
- CPU policy.
- Storage free space.
- Template locality.
- Network availability.
- Current job pressure.
- Provider tags.
- Project/course affinity.
- Anti-affinity.
- Reserved capacity.
- Maintenance windows.

Placement decisions are audit logged with the selected target and reasons.

## Scale

Scale profiles map onto the two Podman deployment profiles defined in the orchestration spec (Baseline = Quadlets + systemd, single host; Scale = Nomad with the Podman driver, multi-host):

- **Tiny / Baseline (one host)**: Quadlets manage web, workers, PostgreSQL, Redis, and local artifact storage on the same host. Manual replica counts.
- **Small lab (Baseline)**: separate Proxmox hosts; one Baseline RackLab host with all RackLab services co-located. Worker replica counts adjusted manually as load grows.
- **Department (Scale)**: Nomad-managed RackLab containers on multiple Podman hosts. Web tier has `count >= 2`; worker pools autoscale on Horizon queue depth via Nomad Autoscaler + Prometheus (scraping Pulse metrics or a Horizon-status exporter). PostgreSQL, Redis, and the Nomad agent itself remain Quadlets per the orchestration spec.
- **Large (Scale)**: HA PostgreSQL plan, Redis clustering, fully autoscaled worker pools per queue/provider, isolated untrusted-script-worker hosts (separate Nomad host class), observability stack, documented backup/restore runbook.

Compose is not the scaling backend in any profile. Kubernetes support can be added later but is not the default operational assumption.
