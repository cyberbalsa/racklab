<?php

declare(strict_types=1);

namespace App\Domain\Rbac;

use InvalidArgumentException;

final readonly class Permission
{
    public function __construct(public string $code)
    {
        if (trim($code) === '') {
            throw new InvalidArgumentException('Permission code must not be blank.');
        }
    }
}
