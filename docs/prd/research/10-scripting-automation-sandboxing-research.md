# Scripting, Automation, And Sandboxing Research

## Cloud-Init

cloud-init is a standard first-boot configuration system. It supports modules for users, SSH keys, packages, files, commands, and network-related configuration. Proxmox supports cloud-init drives and custom user/network/meta/vendor data snippets.

Sources:

- cloud-init module reference: https://cloudinit.readthedocs.io/en/stable/reference/modules.html
- cloud-init examples: https://cloudinit.readthedocs.io/topics/examples.html
- Proxmox cloud-init support: https://pve.proxmox.com/wiki/Cloud-Init_Support

## Console Automation

openQA validates the design of console-driven automation. Its test API includes actions such as `assert_screen`, `send_key`, `type_string`, `wait_serial`, `script_run`, screenshots, serial helpers, and log upload. These concepts map directly to isolated lab VMs with no network path.

Sources:

- openQA docs: https://open.qa/docs/
- openQA test API: https://open.qa/api/testapi

## Network Automation

Ansible Runner provides a Python interface for invoking Ansible and inspecting execution. This fits network-reachable post-deployment tasks where SSH or WinRM is available.

Source:

- Ansible Runner Python interface: https://docs.ansible.com/projects/runner/en/devel/python_interface/

## Sandbox Isolation

nsjail provides Linux namespace isolation, cgroups, rlimits, seccomp-bpf, chroot/pivot_root, read-only mounts, custom `/proc`, and network isolation features. It is well suited to untrusted advanced scripts.

Source:

- nsjail: https://github.com/google/nsjail

bubblewrap is a low-level sandboxing tool used by Flatpak. Its own documentation notes that the caller is responsible for defining the security model and choosing safe arguments. This supports the PRD requirement that RackLab ship hardened runner profiles rather than allowing ad hoc profiles.

Sources:

- bubblewrap: https://github.com/containers/bubblewrap
- Flatpak sandbox model: https://github.com/flatpak/flatpak/wiki/Sandbox

## Design Impact

- Cloud-init wizard should validate YAML and discourage passwords where SSH keys work.
- Console automation should be declarative first and advanced code second.
- Advanced scripts need separate workers with no provider credentials.
- Approval must be tied to immutable script content digests.
- Script artifacts need retention, redaction, and RBAC filtering.
