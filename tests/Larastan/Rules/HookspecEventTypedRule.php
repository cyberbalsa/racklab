<?php

declare(strict_types=1);

namespace Tests\Larastan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<Class_>
 */
final class HookspecEventTypedRule implements Rule
{
    public function getNodeType(): string
    {
        return Class_::class;
    }

    /**
     * @return array<int, RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $this->isHookspecEventFile($scope->getFile())) {
            return [];
        }

        $errors = [];

        if (! $node->isReadonly()) {
            $errors[] = RuleErrorBuilder::message('Hookspec event classes must be readonly.')->build();
        }

        foreach ($node->stmts as $statement) {
            if ($statement instanceof Property && ! $statement->type instanceof Node) {
                $errors[] = RuleErrorBuilder::message('Hookspec event properties must be typed.')->build();
            }

            if ($statement instanceof ClassMethod && $statement->name->toString() === '__construct') {
                foreach ($statement->params as $parameter) {
                    if ($parameter->flags !== 0 && $parameter->type === null) {
                        $errors[] = RuleErrorBuilder::message('Promoted hookspec constructor properties must be typed.')->build();
                    }
                }
            }
        }

        return $errors;
    }

    private function isHookspecEventFile(string $file): bool
    {
        $file = str_replace('\\', '/', $file);

        return str_contains($file, '/app/Events/Hookspecs/')
            && str_ends_with($file, 'Event.php');
    }
}
