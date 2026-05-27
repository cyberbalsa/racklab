# Sources

Research sources used while shaping this PRD:

- RLES slide deck: https://www.se.rit.edu/~swen-440/slides/instructor-specific/Kiser/RLES.pdf
- Laravel documentation: https://laravel.com/docs/
- Laravel Sanctum (API tokens + SPA auth): https://laravel.com/docs/sanctum
- Laravel Passport (OAuth2 / JWT — historical source; Sanctum + `firebase/php-jwt` is the adopted approach): https://laravel.com/docs/passport
- Spatie Laravel Permission (RBAC): https://spatie.be/docs/laravel-permission/
- Laravel Horizon: https://laravel.com/docs/horizon
- Laravel Reverb: https://laravel.com/docs/reverb
- Proxmox VE API client (PHP, Guzzle-based): see `docs/superpowers/specs/2026-05-24-proxmox-client-discipline.md`
- Proxmox VE documentation: https://pve.proxmox.com/pve-docs/
- Proxmox SDN documentation: https://pve.proxmox.com/pve-docs/chapter-pvesdn.html
- OpenStack Neutron concepts: https://docs.openstack.org/neutron/
- OpenStack Network API v2 reference: https://docs.openstack.org/api-ref/network/v2/
- openQA documentation: https://open.qa/docs/
- openQA test API: https://open.qa/api/testapi
- HashiCorp Packer Proxmox plugin: https://developer.hashicorp.com/packer/integrations/hashicorp/proxmox/latest/components/builder/iso
- Composer documentation: https://getcomposer.org/doc/
- PHPStan documentation: https://phpstan.org/user-guide/getting-started
- Psalm documentation: https://psalm.dev/docs/

_Note: The original Python-stack references (framework docs, REST library, auth library, JWT library, type stubs, linter, static analysis tools, automation runner, package manager) have been removed following the stack pivot to Laravel/PHP. The historical library research has also been removed. Stack provenance and library-selection rationale for the current PHP stack live in `docs/superpowers/specs/2026-05-26-laravel-redesign.md`._
