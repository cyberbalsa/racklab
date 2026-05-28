<?php

declare(strict_types=1);

namespace Tests\Larastan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<StaticCall>
 */
final class NoBareEventDispatchOnHookspecsRule implements Rule
{
    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    /**
     * @return array<int, RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($this->isHookDispatcher($scope->getFile())) {
            return [];
        }

        if (! $this->isEventFacadeCall($node)) {
            return [];
        }

        $firstArgValue = $node->args[0]->value ?? null;
        $firstArg = $firstArgValue instanceof Node ? $firstArgValue : null;

        if (! $this->referencesHookspecEvent($firstArg)) {
            return [];
        }

        return [
            RuleErrorBuilder::message('Hookspec events must be dispatched through App\\Plugins\\HookDispatcher.')->build(),
        ];
    }

    private function isHookDispatcher(string $file): bool
    {
        return str_ends_with(str_replace('\\', '/', $file), '/app/Plugins/HookDispatcher.php');
    }

    private function isEventFacadeCall(StaticCall $node): bool
    {
        if (! $node->class instanceof Name || ! $node->name instanceof Node\Identifier) {
            return false;
        }

        $class = $node->class->toString();
        $method = $node->name->toString();

        return in_array($class, ['Event', \Illuminate\Support\Facades\Event::class], strict: true)
            && in_array($method, ['dispatch', 'until'], strict: true);
    }

    private function referencesHookspecEvent(?Node $node): bool
    {
        if ($node instanceof ClassConstFetch && $node->class instanceof Name) {
            return $this->isHookspecClass($node->class->toString());
        }

        if ($node instanceof New_ && $node->class instanceof Name) {
            return $this->isHookspecClass($node->class->toString());
        }

        return false;
    }

    private function isHookspecClass(string $class): bool
    {
        return str_contains($class, 'Events\\Hookspecs\\');
    }
}
