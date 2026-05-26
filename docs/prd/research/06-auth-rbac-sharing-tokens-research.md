# Auth, RBAC, Sharing, And Tokens Research

## Django Auth Foundation

Django's auth system supports users, groups, permissions, authentication backends, custom user models, and permission checks. Django's default backend does not implement object permissions itself, but its APIs accept an object parameter so object permission backends can be added.

Sources:

- Django auth customization: https://docs.djangoproject.com/en/5.2/topics/auth/customizing/
- Django auth reference: https://docs.djangoproject.com/en/5.2/ref/contrib/auth/

## Object Permissions

RackLab needs object-level authorization for projects, deployments, networks, scripts, catalog versions, and token grants. django-guardian is a mature Django option for object permissions and fits this requirement better than only relying on global Django model permissions.

Source:

- django-guardian documentation: https://django-guardian.readthedocs.io/en/latest/

## OAuth, OIDC, And SAML

django-allauth supports local account workflows and external identity providers, including OAuth/OIDC and SAML. That matches the requirement to scale from local accounts to enterprise SSO.

Sources:

- django-allauth introduction: https://docs.allauth.org/en/latest/introduction/index.html
- django-allauth SAML provider: https://docs.allauth.org/en/dev/socialaccount/providers/saml.html
- django-allauth features: https://allauth.org/features/

## Delegated Token Permissions

Proxmox API tokens provide a useful pattern: privilege-separated tokens cannot exceed the permissions of their backing user. RackLab should mirror this for API tokens so a user cannot mint a token with permissions they do not already have unless an explicit admin/service policy grants it.

Source:

- Proxmox user management and API tokens: https://pve.proxmox.com/pve-docs/chapter-pveum.html

## Design Impact

- Use Django auth as identity foundation, but create RackLab-specific role bindings for project/course/catalog/deployment scope.
- Use object permissions for final authorization decisions.
- Treat sharing as a durable grant with audit history.
- Use delegated token grants that are always bounded by issuer permissions unless an admin policy explicitly overrides it.
