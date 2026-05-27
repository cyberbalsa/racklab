<?php

declare(strict_types=1);

namespace Tests\Larastan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;

/**
 * Stub for the HookspecEventTypedRule from spec §8.
 *
 * Real implementation: every class under app/Events/Hookspecs/{Domain}/{Verb}Event.php
 * must be `final readonly` (or `final` with all properties readonly) and
 * have typed promoted-constructor properties. At scaffold time there are
 * no hookspec event classes yet (they land in plugin-lifecycle), so the
 * rule applies to no nodes.
 *
 * @implements Rule<Node>
 */
final class HookspecEventTypedRule implements Rule
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
        // Stub — see plugin-lifecycle sub-plan for the real implementation.
        return [];
    }
}
