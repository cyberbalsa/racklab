<?php

declare(strict_types=1);

namespace Tests\Larastan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;

/**
 * Stub for the @untenanted CI gate from spec §8.
 *
 * Real implementation lands in the tenancy-auth sub-plan once the
 * Tenant model + the #[Untenanted] PHP attribute + the global TenantScope
 * are in place. At scaffold time, the rule applies to no nodes and
 * therefore trivially passes.
 *
 * @implements Rule<Node>
 */
final class UntenantedRule implements Rule
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
