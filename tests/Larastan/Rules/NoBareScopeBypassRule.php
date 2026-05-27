<?php

declare(strict_types=1);

namespace Tests\Larastan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;

/**
 * Stub for the NoBareScopeBypassRule from spec §8.
 *
 * Real implementation: any call to ->withoutGlobalScopes() or
 * ->withoutGlobalScope(TenantScope::class) outside
 * app/Domain/Tenancy/CrossTenantFetch.php is a security violation.
 * At scaffold time the TenantScope and CrossTenantFetch don't exist
 * yet (they land in tenancy-auth), so the rule applies to no nodes.
 *
 * @implements Rule<Node>
 */
final class NoBareScopeBypassRule implements Rule
{
    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return array<int, RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // Stub — see tenancy-auth sub-plan for the real implementation.
        return [];
    }
}
