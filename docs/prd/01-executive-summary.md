# Executive Summary

RackLab is a replacement for RLES: a self-service educational lab platform where students and instructors can deploy VMs and multi-VM stacks from a catalog, manage them safely, connect to consoles, run post-deployment automation, and share access with controlled permissions.

The original RLES workflow is lightweight: users log in, choose a VM from a catalog, submit a deployment request, wait for progress, then power on and connect to a remote console. RackLab keeps that approachable workflow while expanding it into a modern control plane for courses, student projects, stack templates, quotas, networking, automation, auditing, and multiple infrastructure providers.

RackLab is built around:

- Django as the product control plane.
- PostgreSQL as the system of record.
- NATS JetStream as the durable job and event bus.
- Separate worker pools for provider actions, scripting, console automation, reconciliation, and notifications.
- Proxmox as the first provider backend.
- A plugin system for providers, networking, scripts, consoles, auth, notifications, quotas, placement, and audit sinks.
- Container-first deployment via Podman, with two profiles: a Quadlets + systemd **Baseline** for single-host installs and a **Scale** profile layering HashiCorp Nomad with the Podman driver for multi-host autoscaling deployments. Docker / Podman Compose remains a development and example-stack surface, not the deployment runtime. See the Podman orchestration spec for details.

The product must scale down to a tiny install for 1-2 users while scaling up to thousands of users by separating web, worker, database, event bus, artifact storage, and untrusted script execution.
