# Auth, RBAC, Sharing, And Tokens

## Authentication

RackLab must support auth modes ranging from tiny labs to enterprise SSO:

- Local accounts.
- Guest links.
- Invitation links.
- OAuth/OIDC.
- SAML.
- Upstream group/claim mapping into RackLab roles.

Django auth remains the base. `django-allauth` is the preferred integration layer for local, OAuth/OIDC, and SAML workflows unless implementation research identifies a stronger Django-native option.

## RBAC Model

Authorization is object and scope based.

Every durable platform resource exposes the four canonical CRUD permissions:

- `resource.read`
- `resource.create`
- `resource.update`
- `resource.delete`

This is non-optional for core apps and first-party plugins. If a resource is visible, creatable, editable, or removable through the UI, API, admin surface, worker action, plugin hook, or automation token, the matching CRUD permission exists and can be assigned to a role. Domain-specific operations such as `deployment.power`, `deployment.console`, `deployment.snapshot.restore`, `script.approve`, or `token.delegate_role` are additional operation permissions; they do not replace CRUD coverage.

Roles can be composed from:

- Direct permissions.
- Nested permission packs.
- Presets that bundle packs and direct permissions into a named starting point.

Permission packs are reusable trees: a pack can include direct permissions and child packs. Presets are user-facing templates such as "Course instructor", "Console-only user", or "Catalog publisher". A role may start from a preset and then add or remove narrower permissions through role bindings, but the effective permission set is always expanded and auditable.

The built-in catalog is synchronized into the database, not hand-maintained row by row. Core defaults ship as resource-level CRUD packs, aggregate packs, and preset seeds; the sync path is idempotent so deployments can refresh default permissions after upgrades without duplicating rows.

Role bindings assign a role to a principal (`user`, `group`, API credential, service identity, or guest grant) at a scope. Global bindings apply everywhere; scoped bindings apply only to the matching organization, course, project, catalog item/version, deployment, network, script, or token grant. Access checks expand direct role permissions, preset permissions, and nested packs, then explain the binding path in audit/debug surfaces.

Scopes:

- Global platform.
- Organization.
- Course.
- Project.
- Catalog item/version.
- Deployment/stack.
- Network.
- Script.
- Token grant.

Example roles:

- Global admin.
- Provider admin.
- Audit admin.
- Instructor.
- Course assistant.
- Project owner.
- Project admin.
- Stack operator.
- Console-only user.
- Power-only user.
- Script author.
- Script approver.
- Catalog publisher.

Example permissions:

- `deployment.read`
- `deployment.create`
- `deployment.update`
- `deployment.delete`
- `deployment.resource.read`
- `deployment.resource.create`
- `deployment.resource.update`
- `deployment.resource.delete`
- `deployment.power`
- `deployment.console`
- `deployment.snapshot.restore`
- `project.read`
- `project.create`
- `project.update`
- `project.delete`
- `project.share`
- `project.admin`
- `catalog.publish`
- `network.create_private`
- `network.create_nat`
- `network.attach_provider`
- `network.allocate_public_ip`
- `script.openqa.create`
- `script.cloudinit.create`
- `script.advanced_code.create`
- `script.run_unapproved`
- `script.approve`
- `token.create`
- `token.delegate_role`

## Sharing

Users can share projects, VMs, stacks, networks, and scripts when RBAC allows it. Sharing can grant narrow access, including console only, power controls only, snapshot restore, stack operator, script runner, or project admin.

Instructors can deploy stacks for a list of students and retain management access for those course-created deployments.

## Guest Links

Guest links are:

- Signed.
- Scoped.
- Time-limited.
- Revocable.
- Audit logged.
- Never broader than the resource and action explicitly granted.

Guest links can grant console, view, or temporary lab access without creating a full user account.

## API Tokens

RackLab issues tokens on two tracks with different lifetimes and different shapes. See [Public API, OpenAPI, And SSE](07-api-openapi-sse.md) for the wire-protocol details.

**Track A — Short-lived signed JWTs** (browser session, console grant, share link, short-lived deployment token). RS256-signed; carry standard `iss`/`aud`/`sub`/`exp`/`iat`/`nbf`/`jti` claims plus RackLab-specific grant id, scope, tenant, project/course constraints, and permission set. Verifying public key can be exposed to sidecar services (noVNC websockify proxy, SSH-plugin gateway) without sharing the signing key. Blacklist by `jti` for early revocation; expiry is short (minutes to hours).

**Track B — Long-lived opaque Personal Access Tokens (PATs)** (named token grants for agents, CLIs, plugin webhooks). Server-stored, hashed at rest (bcrypt-style); the raw bearer secret is shown to the user **once** at issuance and never re-displayed. Revocation is server-side — delete the row, the token stops working immediately, no blacklist propagation. Each PAT carries the same grant metadata as the JWT track (scope, tenant, allowed IPs/CIDRs, delegated permissions and roles, audit metadata) on the server side; the wire token itself carries only the opaque bearer secret.

Both tracks share:

- Token creation, use, denial, and revocation are audit logged.
- Tokens cannot exceed the effective permissions of their owner unless created by an admin/service-account policy.
- The `Authorization` header dispatch is `Authorization: Bearer <jwt>` for Track A and `Authorization: Token <opaque>` for Track B; the auth backend picks based on the prefix.
- Allowed IPs/CIDRs and scope policies enforce on every use, not just on issuance.
