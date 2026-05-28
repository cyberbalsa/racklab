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
 * Forbids tenant global-scope bypasses outside CrossTenantFetch.
 *
 * @implements Rule<Node>
 */
final class NoBareScopeBypassRule implements Rule
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

        if (! in_array($node->name->toString(), ['withoutGlobalScope', 'withoutGlobalScopes'], strict: true)) {
            return [];
        }

        if ($this->isAllowedPath($scope->getFile())) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'Bare Eloquent global-scope bypass is forbidden outside app/Domain/Tenancy/CrossTenantFetch.php. Use CrossTenantFetch so cross-tenant reads carry provenance and emit tenant.cross_access audit rows.'
            )->build(),
        ];
    }

    private function isAllowedPath(string $filename): bool
    {
        return str_ends_with(
            str_replace('\\', '/', $filename),
            '/app/Domain/Tenancy/CrossTenantFetch.php',
        );
    }
}
