# M1 — Auth + Core Identity

**Status:** Not started.
**Estimated effort:** 2–3 weeks.
**Depends on:** M0.
**Unblocks:** M2, M8, and any milestone that needs an authenticated user.

## Goal

Wire authentication, identity, and project/course scoping into the M0 skeleton so RackLab has real users with real RBAC. A developer (or a student in a development deployment) can log in via username/password, land on a logged-in dashboard scaffold, see their courses and projects, and have their session attributed to a real `UserProfile` row with the right RBAC bindings. First login or registration creates the user's personal Project and grants them Project Owner.

## In scope

- PRD §06 auth, RBAC, sharing, and tokens (the auth and core RBAC parts; tokens land here too because they share schema with users).
- PRD §03 users and personas (the Student / Instructor / Admin / Guest model surfaced in code).
- PRD §15 baseline branding (logo + name + favicon + primary brand color — admin GUI for theming lands in M10, but the data model and the per-deployment defaults land here).

## Dependencies

- M0 RBAC primitives (`Permission`, `Role`, `RoleBinding`).
- M0 audit subsystem (every auth event is audited).
- M0 i18n scaffolding (login / signup / error pages are translated).

## Deliverables

- Laravel Fortify configured for local username/password auth (login, registration, password reset, 2FA scaffolding, passkey support) in dev. OAuth/OIDC/SAML adapters are pluggable via the auth plugin family (concrete adapters land in their respective plugin packages); M1 ships local auth only. Password hashing via `Hash::driver('argon2id')`.
- `App\Domain\Identity` namespace: `UserProfile`, `Organization`, `Course`, `Enrollment`, `Project`, `ProjectMembership`, `ProjectSSHKey`, `Group` Eloquent models + associated migrations.
- First-login personal Project creation: every new user gets a personal Project (`created_for_user_id`, `is_personal_default`) in their primary tenant and a tenant-local Project Owner role binding. The Project's reserved Default StackDefinition/Deployment pointers are created in M2 when the Stack/Deployment tables exist.
- Primary-tenant rule: local registration uses the deployment default tenant; SSO/OIDC/SAML login must resolve tenant membership before personal Project creation. There is at most one personal default Project per `(tenant_id, user_id)`.
- The `Student` / `Instructor` / `Admin` / `Guest` role model in the database, with `RoleBinding` wiring users to courses or projects.
- `ShareGrant` + `Invitation` + `GuestLink` models. The share-link primitive is the canonical sharing mechanism for everything downstream (docs, deployments, SSH sessions all reuse it).
- `TokenGrant`, `TokenUse`, `TokenRevocation`, `SigningKey` per PRD §06 + §07 — the two-track token surface. **Track A** (signed JWT, short-lived) via `firebase/php-jwt` ^7.0 with RS256 + JWK rotation + `jti` blacklist; implemented in `App\Auth\Jwt\TrackAIssuer`; verifying public keys exported via `App\Http\Controllers\JwksController` (JWKS endpoint) for sidecar services. **Track B** (opaque PAT, long-lived) via Laravel Sanctum opaque tokens with abilities-scoped storage + server-side revoke. `TokenGrant` rows carry tenant FK + `scope_type` (`tenant_local` / `multi_tenant` / `global`) + `tenant_set` per the §19 data model; the `Authorization` header dispatch (`Bearer` → JWT lookup, `Token` → PAT lookup) is implemented. PRD §6 amendment is folded; both tracks share the standard grant metadata, audit on every state change.
- A minimal Filament Admin panel surface for managing users / courses / projects.
- A bare-bones authenticated UI: login page (Blade-rendered via Fortify), "you're logged in" landing dashboard (Livewire 4 component with daisyUI styling), logout, "set my locale" preference. Branding driven by deployment defaults (no admin GUI yet).
- API controller wiring for the first authenticated endpoints (`/api/v1/me`, `/api/v1/projects`, `/api/v1/courses`), with Scribe generating a real OpenAPI schema. Controllers and Livewire components delegate permission checks to `App\Domain\Tenancy\AccessResolver`.
- `php artisan racklab:sync-rbac-defaults` command now covers every Student / Instructor / Admin / Guest default role; permission-snapshot test updated accordingly.

## Acceptance criteria

- [ ] A user can sign up, log in, set their locale, and log out via the browser UI.
- [ ] Every M1-shipped auth event (`login`, `logout`, `failed_login`, `signup`, `password_change`) is audit-logged with actor, IP, user agent, and outcome. The audit-event **schema** for `oauth_link_attempt` and `saml_link_attempt` is registered in M1 (so the audit-emission CI test won't fail when an auth plugin lands), but the events themselves are emitted by the OAuth/OIDC and SAML auth plugins (`racklab-auth-socialite-oidc`, `racklab-auth-socialite-saml2`) — separate plugin packages, not part of the M1 deliverable.
- [ ] A newly registered student sees exactly their personal Project and no courses — they cannot enumerate other users' projects.
- [ ] A user who belongs to two tenants receives separate personal Projects per tenant only after logging into each tenant context; changing primary tenant never moves an existing Project across tenants.
- [ ] An instructor with a course role binding can list course members, see their assigned projects, and create a project; a student in the course cannot.
- [ ] A `GuestLink` can be issued for a project view, the link works for 1 hour, revocation is immediate, and access is audit-logged.
- [ ] A Track-A JWT can be issued with a subset of the issuer's permissions; using it grants exactly that subset; revoking by `jti` invalidates the token within one request cycle.
- [ ] A Track-B opaque Sanctum PAT can be issued (raw bearer shown once); using it grants exactly the bound abilities; **revoking the `TokenGrant`** (sets `revoked_at` + writes a paired `TokenRevocation` row + deletes the Sanctum `personal_access_tokens` row that holds the hash) stops the token from working immediately, no blacklist propagation latency. The `TokenGrant` row itself is retained (soft-revoked) so audit references and `TokenUse` rows stay resolvable; the Sanctum token row that held the hash is what's actually deleted under the hood.
- [ ] Both tracks dispatch correctly from the `Authorization` header (`Bearer` → JWT, `Token` → PAT); a request with the wrong prefix is rejected.
- [ ] Token issuance respects tenant scope: a tenant-local issuer cannot issue a `multi_tenant` or `global` token; attempting to escalate emits a `tenant.cross_access` audit event with `result=denied, reason=insufficient_scope`.
- [ ] Permission-snapshot test passes against the M1-shipped role definitions; any PR that changes a default role's permissions must update the snapshot.
- [ ] The `/api/v1/me` endpoint returns the authenticated user's profile with their effective permissions; Scribe's generated OpenAPI schema accurately describes it.

## Test layers

- **Unit (Pest 4)**: JWT claim construction and validation via `TrackAIssuer`; permission-predicate logic; the share-link signing/verifying primitives; pluralisation rendering for "you have N projects" style strings.
- **Feature (Pest 4)**: Fortify auth integration; API middleware permission classes; the share-link primitive at the domain boundary; the i18n locale-resolution chain (per-user → Accept-Language → deployment default).
- **Integration (Pest 4 + Testcontainers)**: login flow with audit emission verified end-to-end; API token issuance + revocation + `jti` blacklist hit; share-link issue → consume → revoke against a real Postgres; the OpenAPI schema is regenerated and matches the committed snapshot.
- **E2E** (first E2E flow lands here, via Laravel Dusk): browser logs in, sets locale, opens the landing dashboard, logs out — axe-core runs on every page in this flow and the build fails on any new violations.

## Risks / open questions

- **OIDC / OAuth / SAML adapters as plugins**: M1 ships only local auth. The plugin family `racklab-auth-*` exists in the framework but the concrete plugins (`racklab-auth-socialite-oidc` using `Kovah/laravel-socialite-oidc`, `racklab-auth-socialite-saml2` using `socialiteproviders/saml2`) are post-M1 work. Make sure the local-auth flow doesn't bake assumptions that block the Socialite plugin path.
- **JWT signing key rotation in dev**: rotation works but the dev UX of "I rotated my key and now my old tokens don't work" needs a clear admin-UI message.
- **Email delivery for password reset / invitations**: M1 uses Laravel's `log` mail driver for dev. Real email lands with the notification plugin family.
- **The `Group` model is overloaded**: Laravel has no built-in `Group`, but PRD §19 lists `Group` as a domain concept. Custom Eloquent model adds migration management; it needs course/project scoping. Decide before M1 implementation — custom is the clear path since scoped membership metadata is required.
- **Fortify + Filament co-existence**: Filament ships its own authentication layer; confirm guard/provider config so Fortify's local-auth routes and Filament's admin auth don't conflict.

## Out of scope (deferred)

- OAuth / OIDC / SAML provider integrations — separate plugin packages, not part of M1.
- Theming/branding admin GUI — M10.
- Full Key Screens for Student / Instructor / Admin — M10. M1 ships only the minimum UI to verify the auth flow works.
- Two-factor authentication full UX — Fortify scaffolding ships in M1, but the polished 2FA + passkey management UI is M10.
- Real notification delivery — gated on the notification plugin family (M7a/M7b or later).
- API rate limiting — lands alongside production-readiness in M11a/M13d or earlier if the deployment lifecycle needs it.
