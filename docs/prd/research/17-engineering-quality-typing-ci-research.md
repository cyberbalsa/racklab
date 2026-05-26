# Engineering Quality, Typing, And CI Research

## uv

uv projects use `pyproject.toml` and create a `uv.lock` lockfile. The lockfile captures exact resolved package versions and should be checked into version control for reproducible development and deployment. uv workspaces can manage multiple packages with one lockfile, useful if RackLab later splits core and plugins in one repository.

Sources:

- uv project layout: https://docs.astral.sh/uv/concepts/projects/layout/
- uv workspaces: https://docs.astral.sh/uv/concepts/projects/workspaces/

## Ruff

Ruff provides linting and formatting in one toolchain and supports configuration in `pyproject.toml`. The formatter can run in check mode for CI. This fits the strong linting requirement.

Sources:

- Ruff formatter: https://docs.astral.sh/ruff/formatter/
- Ruff configuration: https://docs.astral.sh/ruff/configuration/

## Django-Aware Typing

django-stubs provides Django type stubs and a mypy plugin that uses Django runtime metadata to improve static analysis. This is the key Django-aware type checker path. djangorestframework-stubs covers DRF-specific typing.

Sources:

- django-stubs: https://github.com/typeddjango/django-stubs
- mypy documentation: https://mypy.readthedocs.io/

## Pyright And Pylance

Pyright is Microsoft's static type checker and supports strict type checking configuration. Pylance is the VS Code language server built around Pyright technology. RackLab should run Pyright or basedpyright in CI and recommend Pylance for editor feedback.

Sources:

- Pyright: https://github.com/microsoft/pyright
- Pyright configuration: https://github.com/microsoft/pyright/blob/main/docs/configuration.md
- Pylance: https://github.com/microsoft/pylance-release

## Design Impact

- Use mypy plus django-stubs as the Django-aware CI gate.
- Use Pyright/basedpyright as a second strict checker for editor/CI coverage.
- Type plugin contracts, provider APIs, NATS payloads, token claims, script definitions, and audit events.
- Use runtime validation for all process and trust boundaries.
- CI should fail on lint, format, type, tests, OpenAPI drift, dependency audit, and security scan failures.
