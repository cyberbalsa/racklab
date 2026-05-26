# Plugin System Research

## Python Entry Points

Python packaging entry points let installed distributions advertise plugin objects that can be discovered at runtime through `importlib.metadata`. This is the right discovery layer for RackLab plugin packages installed with `uv`.

Source:

- PyPA entry points specification: https://packaging.python.org/specifications/entry-points/

## Hook System

pluggy is the plugin management and hook calling system used by pytest. It provides hook specifications, hook implementations, and plugin registration. This fits RackLab's need for stable core-defined contracts.

Source:

- pluggy documentation: https://pluggy.readthedocs.io/

## Dynamic Extension Management

stevedore, from the OpenStack ecosystem, is another entry-point-based plugin manager. It validates the broader pattern of using entry points for dynamic extension loading, though RackLab's hook contracts are likely better served by pluggy.

Source:

- stevedore documentation: https://docs.openstack.org/stevedore/latest/

## Django Reusable Apps

Django apps can package models, admin integrations, templates, views, and migrations. RackLab plugins that contribute database models must be handled carefully because migrations and install/enable order become operational concerns.

Source:

- Django applications: https://docs.djangoproject.com/en/5.2/ref/applications/

## Design Impact

- Use entry points for discovery and pluggy for hook contracts.
- Keep plugin APIs versioned and capability-based.
- Plugins that add Django models need explicit migration lifecycle support.
- Provider plugins must be idempotent and health-checkable.
- Plugin failures should degrade capabilities, not crash the control plane.
