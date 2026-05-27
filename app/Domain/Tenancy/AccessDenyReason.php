<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

enum AccessDenyReason: string
{
    case InsufficientScope = 'insufficient_scope';
    case PermissionNotGranted = 'permission_not_granted';
    case ResourceNotVisible = 'resource_not_visible';
}
