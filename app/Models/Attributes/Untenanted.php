<?php

declare(strict_types=1);

namespace App\Models\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Untenanted
{
    public function __construct(public string $reason) {}
}
