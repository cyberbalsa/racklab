<?php

declare(strict_types=1);

namespace Tests\Larastan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;

/**
 * Stub for the NoSpatieBypassRule from spec §8.
 *
 * Real implementation: any call to $user->hasRole(...) or $user->can(...)
 * outside App\Domain\Tenancy\AccessResolver is a security violation —
 * AccessResolver is the only authorisation gatekeeper per spec §5.
 * At scaffold time the User model is unchanged Laravel default and
 * AccessResolver doesn't exist (lands in tenancy-auth), so the rule
 * applies to no nodes.
 *
 * @implements Rule<Node>
 */
final class NoSpatieBypassRule implements Rule
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
