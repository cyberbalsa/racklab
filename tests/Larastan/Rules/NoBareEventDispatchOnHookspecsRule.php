<?php

declare(strict_types=1);

namespace Tests\Larastan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;

/**
 * Stub for the NoBareEventDispatchOnHookspecsRule from spec §8.
 *
 * Real implementation: any call to Event::dispatch(SomeHookspec\Event::class)
 * or Event::until(SomeHookspec\Event::class) outside
 * app/Plugins/HookDispatcher.php is a violation. All hookspec dispatch
 * must go through the typed HookDispatcher per spec §6. At scaffold time
 * HookDispatcher and the hookspec event classes don't exist (they land
 * in plugin-lifecycle), so the rule applies to no nodes.
 *
 * @implements Rule<Node>
 */
final class NoBareEventDispatchOnHookspecsRule implements Rule
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
