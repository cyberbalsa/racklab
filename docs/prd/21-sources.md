# Sources

Research sources used while shaping this PRD:

- RLES slide deck: https://www.se.rit.edu/~swen-440/slides/instructor-specific/Kiser/RLES.pdf
- Laravel documentation: https://laravel.com/docs/
- Laravel Sanctum (API tokens + SPA auth): https://laravel.com/docs/sanctum
- Laravel Sanctum + `firebase/php-jwt` are the adopted API-token/JWT approach; Passport is not part of the RackLab stack.
- Spatie Laravel Permission (RBAC): https://spatie.be/docs/laravel-permission/
- Laravel Horizon: https://laravel.com/docs/horizon
- Laravel Reverb: https://laravel.com/docs/reverb
- Proxmox VE API client (PHP, codegen-from-schema + Guzzle transport per the discipline spec): see `docs/superpowers/specs/2026-05-24-proxmox-client-discipline.md`
- Proxmox VE documentation: https://pve.proxmox.com/pve-docs/
- Proxmox SDN documentation: https://pve.proxmox.com/pve-docs/chapter-pvesdn.html
- OpenStack Neutron concepts: https://docs.openstack.org/neutron/
- OpenStack Network API v2 reference: https://docs.openstack.org/api-ref/network/v2/
- OpenStack Compute API keypairs reference (used as product precedent for keypair-style VM public-key injection): https://docs.openstack.org/api-ref/compute/
- DMTF Open Virtualization Format (OVF/OVA packaging precedent for Stack export): https://www.dmtf.org/standards/ovf
- openQA documentation: https://open.qa/docs/
- openQA test API: https://open.qa/api/testapi
- HashiCorp Packer Proxmox plugin: https://developer.hashicorp.com/packer/integrations/hashicorp/proxmox/latest/components/builder/iso
- Composer documentation: https://getcomposer.org/doc/
- PHPStan documentation: https://phpstan.org/user-guide/getting-started
- Psalm documentation: https://psalm.dev/docs/

_Stack provenance and library-selection rationale for the current PHP stack live in `docs/superpowers/specs/2026-05-26-laravel-redesign.md`._
