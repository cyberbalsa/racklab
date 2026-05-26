# Secret backend abstraction — plan

**Slice:** Closes the M0 deliverable "Secret backend abstraction — Protocol +
a dev-only filesystem backend; real backends are plugins" (M00-foundations.md
line 35) and the contract-test entry (M00-foundations.md line 77).
**Date:** 2026-05-26.
**Status:** Plan (pre-codex review).

## Goal

Land the load-bearing PRD §13 + §18 contract surface that every later
credential-handling milestone (provider credentials → M2; script secrets →
M2/M4; SSH host keys → M4/M9; OAuth client secrets → M1) builds against.

After this slice:

- `SecretBackend` Protocol exists, typed, with the full PRD §13/§18 method
  surface (`put_secret`, `get_secret`, `delete_secret`, `list_secrets`,
  `rotate_keys`, `health`).
- A `FilesystemSecretBackend` reference implementation backed by
  `cryptography.fernet.MultiFernet` ships in core. Per-tenant partitioning,
  atomic-rename writes, master + rotation keys from env, key-rotation that
  re-encrypts every blob under the leading key.
- Every read / write / delete / rotate emits an audit event with **redacted**
  payload (never the plaintext, never the ciphertext — just the secret name +
  tenant + actor + operation + sha256 of ciphertext for integrity).
- Settings registry mirrors the M0 ArtifactBackend pattern:
  `RACKLAB_SECRET_BACKEND_REGISTRY` (dict) + `RACKLAB_SECRET_BACKEND_DEFAULT`
  (str) + `get_secret_backend(name)` resolver.
- Contract tests + integration tests cover the full M00-foundations.md line 77
  acceptance: "the secret-backend Protocol against the dev filesystem backend".

## In scope

### Core files (NEW)

- `src/racklab/core/secrets.py` — Protocol + dataclasses + exception hierarchy
  + `get_secret_backend(name)` resolver. Mirrors the shape of
  `src/racklab/core/storage.py`.
- `src/racklab/core/secrets_filesystem.py` — `FilesystemSecretBackend`
  implementation. Mirrors the shape of `src/racklab/core/storage_filesystem.py`.
- `src/racklab/core/states.py` — adds `SecretBackendHealthStatus` enum
  (`OK` / `DEGRADED` / `UNHEALTHY`) — reuses `BackendHealthStatus` if the values
  are identical; otherwise its own enum so secret-side observability stays
  decoupled.

### Protocol surface

```python
class SecretBackend(Protocol):
    @property
    def name(self) -> str: ...
    @property
    def capabilities(self) -> SecretBackendCapabilities: ...

    def put_secret(
        self,
        tenant_id: uuid.UUID,
        secret_name: str,
        plaintext: bytes,
        *,
        metadata: dict[str, str] | None = None,
        actor: SecretActor,
    ) -> SecretRef: ...

    def get_secret(
        self,
        tenant_id: uuid.UUID,
        secret_name: str,
        *,
        actor: SecretActor,
    ) -> SecretPayload: ...

    def delete_secret(
        self,
        tenant_id: uuid.UUID,
        secret_name: str,
        *,
        actor: SecretActor,
    ) -> None: ...

    def list_secrets(
        self,
        tenant_id: uuid.UUID,
        *,
        actor: SecretActor,
    ) -> Iterable[SecretRef]: ...

    def rotate_keys(self) -> RotationResult: ...

    def health(self) -> SecretBackendHealth: ...
```

### Frozen dataclasses

- `SecretBackendCapabilities` — `supports_tenant_partitioning: bool = True`,
  `supports_rotation: bool = True`, `supports_listing: bool = True`,
  `supports_metadata: bool = True`, `audits_internally: bool = False`. The
  filesystem backend sets `audits_internally=False` so the calling service emits
  the audit event; cloud-KMS plugin backends MAY set it True and emit their own
  vendor-side audits.
- `SecretRef` — `tenant_id`, `secret_name`, `version` (monotonic int), `metadata`,
  `created_at`, `updated_at`, `ciphertext_sha256` (sha256 of the ciphertext, NOT
  the plaintext — used for integrity assertions without exposing the secret).
- `SecretPayload` — `ref: SecretRef`, `plaintext: bytes`. The plaintext is
  returned to the caller but never logged or audited.
- `SecretBackendHealth` — `status`, `details`, `checked_at`.
- `RotationResult` — `keys_used: list[str]` (Fernet key fingerprints, NOT the
  raw keys), `secrets_rotated: int`, `failed_secrets: list[str]`.
- `SecretActor` — `actor_type` (`user` / `service` / `plugin` / `system`),
  `actor_identifier` (e.g. `user:<username>`, `plugin:<entry-point>`),
  `tenant_id` (the actor's tenant — used for `tenant.cross_access` checks if
  the actor's tenant differs from the secret's tenant).

### Exception hierarchy

- `SecretBackendError(Exception)` — base.
- `SecretNotFoundError(SecretBackendError)` — `get_secret` / `delete_secret`
  when secret is absent.
- `SecretIntegrityError(SecretBackendError)` — sha256 mismatch on read (tamper
  detection) or MultiFernet decryption failure (no key in the keyring can
  decrypt the ciphertext).
- `SecretBackendConfigurationError(SecretBackendError)` — missing master key,
  malformed env, etc.

### FilesystemSecretBackend

Storage layout under `RACKLAB_SECRET_ROOT`:

```text
<root>/
├── <tenant_id>/
│   ├── <secret_name>.enc          # MultiFernet ciphertext
│   └── <secret_name>.meta.json    # SecretRef metadata (no ciphertext)
```

- **Master + rotation keys**: read from `RACKLAB_SECRET_FERNET_KEYS` env var
  (comma-separated Fernet keys, leading key is the active write key). Backend
  refuses to construct if no keys provided (`SecretBackendConfigurationError`).
- **Encryption**: `MultiFernet(keys).encrypt(plaintext)` — the leading key
  signs new blobs; any key in the keyring can decrypt existing blobs.
- **Path safety**: same `_path_safety()` discipline as
  `FilesystemArtifactBackend` — `..`, `/`, NUL bytes, empty names all raise
  `SecretBackendError`. `secret_name` must match `^[a-z0-9][a-z0-9._-]{0,127}$`.
- **Atomic write**: write to `<secret_name>.enc.tmp` + `.meta.json.tmp`, then
  `os.replace` both. Each file `0o640`, directories `0o750`.
- **Tenant partitioning**: `<root>/<tenant_id>/...`. Cross-tenant reads
  refused — the path lookup uses the explicit `tenant_id` argument; a backend
  can't be tricked into reading another tenant's secret without the caller
  passing that tenant's UUID.
- **`rotate_keys()`**: walks every `<tenant_id>/<secret_name>.enc` blob,
  decrypts with the MultiFernet keyring, re-encrypts under the leading key,
  atomic-renames. Updates `SecretRef.version` to `version + 1` and stamps a
  new `ciphertext_sha256`. Skips blobs that can't be decrypted (logs +
  `RotationResult.failed_secrets`) so a corrupted blob doesn't block the
  whole rotation pass.
- **`health()`**: writes a probe file under `<root>/_probe/`, encrypts then
  decrypts a deterministic payload to verify the MultiFernet keyring is
  functional. `OK` / `DEGRADED` (probe slow but successful) / `UNHEALTHY`
  (probe failed).

### Audit emission

Four new audit event kinds (PRD §14):

- `secret.put` — emitted on `put_secret`. Payload: `{ "secret_name": "<name>",
  "tenant_id": "<uuid>", "actor": "<actor_identifier>", "version": <int>,
  "ciphertext_sha256": "<hex>" }`. **No plaintext, no ciphertext, no metadata
  values.**
- `secret.get` — emitted on `get_secret` (PRD §18 line 11: "Secret reads audit
  logged"). Same payload shape as `secret.put`.
- `secret.delete` — emitted on `delete_secret`.
- `secret.rotate` — emitted on `rotate_keys()`. Payload includes
  `secrets_rotated` count + `failed_secrets` list (names only).

The backend's wrapper service in `src/racklab/core/secret_service.py` owns
audit emission so plugin backends that set
`capabilities.audits_internally=True` can opt out of the wrapper layer.

### Settings

```python
# src/racklab/settings/base.py additions
RACKLAB_SECRET_ROOT = Path(
    os.environ.get("RACKLAB_SECRET_ROOT", str(BASE_DIR / ".racklab" / "secrets")),
)
RACKLAB_SECRET_BACKEND_REGISTRY: dict[str, str] = {
    "filesystem": "racklab.core.secrets_filesystem.FilesystemSecretBackend",
}
RACKLAB_SECRET_BACKEND_DEFAULT = os.environ.get(
    "RACKLAB_SECRET_BACKEND_DEFAULT",
    "filesystem",
)
```

`RACKLAB_SECRET_FERNET_KEYS` is the env var the backend reads at construction
time — *not* a Django setting, so the keys never appear in a `manage.py
diffsettings` dump.

### Dependency

- `cryptography>=44,<50` added to `[project.dependencies]` in `pyproject.toml`.
  Currently transitively installed at 48.0.0 — pin explicit to avoid a future
  upstream drop.

### Tests

- **Tiny (≥ 6 tests)** in `tests/tiny/test_secret_backend_types.py` — frozen
  dataclasses refuse mutation; `SecretBackendCapabilities` defaults; enum
  domains; `SecretActor` validation rejects empty actor_identifier.
- **Contract (4 tests)** in `tests/contract/test_secret_backend_contract.py` —
  in-memory fake `SecretBackend` round-trips a put/get/delete sequence via the
  Protocol; `get_secret_backend("filesystem")` resolves; unknown backend name
  raises `ImproperlyConfigured`.
- **Integration (≥ 14 tests)** in
  `tests/integration/test_filesystem_secret_backend.py`:
  - put/get round-trip
  - missing key raises `SecretNotFoundError`
  - cross-tenant isolation (tenant B can't read tenant A's secret with B's
    tenant_id even if it knows the name)
  - `secret_name` validation (path traversal, NUL, empty, uppercase)
  - delete removes both `.enc` and `.meta.json`
  - list_secrets returns refs for the queried tenant only
  - MultiFernet rotation: encrypt with key A → rotate to keys [B, A] → blob
    is re-encrypted with B; old-key decryption still works during rotation
  - tamper detection: corrupt the `.enc` file → `get_secret` raises
    `SecretIntegrityError` (decryption failure)
  - sha256 mismatch in metadata → `SecretIntegrityError`
  - `health()` returns OK on a writable root, UNHEALTHY when root is read-only
  - relative `RACKLAB_SECRET_ROOT` resolved to absolute (parallel to the
    ArtifactBackend P2 fix)
  - audit emission: every put/get/delete/rotate writes an `AuditEvent` row
    with the redacted payload + the right `kind`
- **Integration (audit-only, 2 tests)** — plaintext does NOT appear in the
  audit payload; ciphertext_sha256 IS present.

## Out of scope (deferred)

- HashiCorp Vault backend (M11+ Scale-profile plugin).
- AWS / Azure / GCP KMS backends (per-tenant plugins).
- Master key bootstrapping via cloud KMS / sealed secrets (M11+).
- Per-secret access policy (which roles can read which secrets) — secrets are
  tenant-scoped at M0; per-role gating lands when the first real consumer
  (provider credentials in M2) does.
- Audit-emit hook for `racklab_secret_pre_get` / `racklab_secret_post_get` —
  the hook contract is part of PRD §13's 80-hookspec catalog but the wiring
  lands when the M2 plugin-system slice does the rest.

## Codex prompt (drafted)

```
Review docs/superpowers/plans/2026-05-26-secret-backend-abstraction.md.

Goal: land the M0 SecretBackend Protocol + FilesystemSecretBackend
(MultiFernet-backed, per-tenant, audit-on-every-op) in a single slice.

Constraints:
- PRD §18 line 11: "Secret reads audit logged" — no plaintext in payload.
- PRD §13: real backends ship as plugins; core has the Protocol + dev
  filesystem backend only.
- No-overrides discipline: no `# noqa`, no `# type: ignore`.
- TDD: tiny + contract + integration tests cover the surface.

Findings I want by severity:
- P0/P1: key-storage / key-rotation correctness bugs; cross-tenant leak
  paths; missing audit payload fields; redaction gaps that would log
  plaintext or ciphertext; missing path-traversal protection.
- a11y / UI: N/A (no UI surface).
- Lint discipline: any plan piece that would force a `# noqa`?
- Plugin integration: does the wrapper-service vs internal-audit
  capability flag make sense?

Be terse, prioritise by severity, don't restate the plan.
```
