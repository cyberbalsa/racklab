# Users And Personas

## Student

Students deploy one-VM or multi-VM Stacks from a catalog or their own project-local Stack definitions, create allowed networks, run approved automation, restore snapshots, connect to consoles, and share controlled access with classmates or groups.

Student needs:

- Fast self-service deployment.
- An automatic personal Project on first login, with a Default Stack for quick VM launches.
- Clear quota and lease visibility.
- Reliable console access.
- Safe reset/restore workflows.
- Project sharing with simple role choices.
- Private or NATed networks when allowed.
- Direct provider network attachment only when permitted.
- Automation without requiring network reachability into the VM.

## Instructor

Instructors create or publish stack templates, attach instructions, define course policies, deploy labs for a list of students, and retain management access for course-created resources.

Instructor needs:

- Stack authoring wizard, including project-local drafts and catalog-published versions.
- Catalog publishing.
- Roster-based deployment.
- Course-level quota and lease policies.
- Script approval and reuse.
- Visibility into student progress and failures.
- Ability to manage or repair student deployments.

## Administrator

Admins configure providers, auth, quota policy, network offerings, plugin settings, audit retention, worker pools, and global policies.

Admin needs:

- Multi-provider inventory visibility.
- Proxmox cluster/server health and capacity tracking.
- Network/provider mapping controls.
- Strong audit search/export.
- Plugin lifecycle management.
- Operational health checks and metrics.
- Safe secret management.
- Branding and theme configuration without template editing.

## Guest

Guests access narrowly scoped resources through signed, revocable, time-limited links. Guest access is for temporary console or lab access and must not imply broad project membership.
