<?php

declare(strict_types=1);

namespace App\Docs\Refs\Resolving\Core;

use App\Docs\Refs\Resolving\RefResolutionContext;
use App\Docs\Refs\Resolving\RefResolver;
use App\Docs\Refs\Resolving\ResolvedRef;
use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Models\Script;

/**
 * Core resolver for `[[script:id]]` cross-links. Gated on `script.read`.
 */
final readonly class ScriptRefResolver implements RefResolver
{
    public function __construct(private AccessResolver $access) {}

    public function kind(): string
    {
        return 'script';
    }

    public function resolve(RefResolutionContext $context, string $id): ResolvedRef
    {
        /** @var Script|null $script */
        $script = Script::query()->whereKey($id)->first();

        if (! $script instanceof Script) {
            return ResolvedRef::notFound($this->kind(), $id);
        }

        $decision = $this->access->permitted(
            $context->actor,
            new Permission('script.read'),
            $script,
            $context->tenant,
        );

        if (! $decision->allowed) {
            return ResolvedRef::redacted($this->kind(), $id);
        }

        return ResolvedRef::resolved($this->kind(), $id, $script->name, '/scripts/'.$id, $script->state);
    }
}
