<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use InvalidArgumentException;

final readonly class ActorIdentity
{
    public function __construct(public string $id)
    {
        if (trim($id) === '') {
            throw new InvalidArgumentException('Actor id must not be blank.');
        }
    }
}
