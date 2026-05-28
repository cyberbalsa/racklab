<?php

declare(strict_types=1);

namespace Tests\Larastan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Forbids direct role/permission checks outside AccessResolver.
 *
 * @implements Rule<Node>
 */
final class NoSpatieBypassRule implements Rule
{
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * @return array<int, RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node instanceof MethodCall) {
            return [];
        }

        if (! $node->name instanceof Identifier) {
            return [];
        }

        if (! in_array($node->name->toString(), ['can', 'hasRole'], strict: true)) {
            return [];
        }

        if ($this->isAllowedPath($scope->getFile())) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'Direct user role/permission checks are forbidden outside app/Domain/Tenancy/AccessResolver.php. Route authorization through AccessResolver so RackLab applies binding scope, resource visibility, and permission grants together.'
            )->build(),
        ];
    }

    private function isAllowedPath(string $filename): bool
    {
        return str_ends_with(
            str_replace('\\', '/', $filename),
            '/app/Domain/Tenancy/AccessResolver.php',
        );
    }
}
