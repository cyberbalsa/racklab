# Source Strategy

## Source Preference

The research notes prefer:

1. Official product documentation.
2. Standards documents and RFCs.
3. Maintainer project documentation and repositories.
4. Comparable system documentation.
5. Community discussions only as secondary context.

## Primary Source Groups

Django and API:

- Django documentation: https://docs.djangoproject.com/en/5.2/
- Django REST Framework: https://www.django-rest-framework.org/
- drf-spectacular: https://drf-spectacular.readthedocs.io/
- django-allauth: https://docs.allauth.org/
- django-guardian: https://django-guardian.readthedocs.io/

Messaging and operations:

- NATS documentation: https://docs.nats.io/
- Docker Compose documentation: https://docs.docker.com/reference/compose-file/
- Podman documentation: https://docs.podman.io/

Provider and cloud:

- Proxmox documentation: https://pve.proxmox.com/pve-docs/
- proxmoxer: https://github.com/proxmoxer/proxmoxer
- OpenStack Neutron: https://docs.openstack.org/neutron/
- OpenNebula documentation: https://docs.opennebula.io/
- Apache CloudStack documentation: https://docs.cloudstack.apache.org/

Automation and sandboxing:

- cloud-init documentation: https://cloudinit.readthedocs.io/
- openQA documentation: https://open.qa/docs/
- openQA test API: https://open.qa/api/testapi
- Ansible Runner documentation: https://docs.ansible.com/projects/runner/
- nsjail: https://github.com/google/nsjail
- bubblewrap: https://github.com/containers/bubblewrap

Quality and security:

- uv documentation: https://docs.astral.sh/uv/
- Ruff documentation: https://docs.astral.sh/ruff/
- django-stubs: https://github.com/typeddjango/django-stubs
- Pyright: https://github.com/microsoft/pyright
- Pylance: https://github.com/microsoft/pylance-release
- OWASP Logging Cheat Sheet: https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html
- OpenTelemetry documentation: https://opentelemetry.io/docs/
- JWT RFC 7519: https://datatracker.ietf.org/doc/rfc7519/
- JWT BCP RFC 8725: https://www.ietf.org/rfc/rfc8725.html

## Maintenance Note

The PRD should be revisited during implementation planning because key dependencies and provider capabilities can change. Research should be refreshed before decisions involving provider API behavior, auth libraries, token libraries, Proxmox SDN management, and sandbox profiles.
