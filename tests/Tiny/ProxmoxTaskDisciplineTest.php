<?php

declare(strict_types=1);

use App\Providers\Proxmox\Models\ProxmoxUpid;

it('decodes Proxmox UPIDs into typed task identity parts', function (): void {
    $upid = ProxmoxUpid::parse('UPID:pve01:0009C3C2:067CF15D:6656B4E1:qmclone:100:root@pam:');

    expect($upid->raw)->toBe('UPID:pve01:0009C3C2:067CF15D:6656B4E1:qmclone:100:root@pam:')
        ->and($upid->node)->toBe('pve01')
        ->and($upid->pid)->toBe(0x0009C3C2)
        ->and($upid->pstart)->toBe('067CF15D')
        ->and($upid->startTime)->toBe(0x6656B4E1)
        ->and($upid->type)->toBe('qmclone')
        ->and($upid->id)->toBe('100')
        ->and($upid->user)->toBe('root@pam');
});
