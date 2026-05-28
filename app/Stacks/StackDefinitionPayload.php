<?php

declare(strict_types=1);

namespace App\Stacks;

use App\Models\StackDefinition;

final readonly class StackDefinitionPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function make(StackDefinition $stack): array
    {
        return [
            'id' => $stack->getKey(),
            'tenant_id' => $stack->tenant_id,
            'project_id' => $stack->project_id,
            'name' => $stack->name,
            'slug' => $stack->slug,
            'scope' => $stack->scope,
            'is_reserved_default' => $stack->is_reserved_default,
            'definition' => $stack->definition ?? [],
        ];
    }
}
