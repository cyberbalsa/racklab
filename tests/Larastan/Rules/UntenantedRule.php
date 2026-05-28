<?php

declare(strict_types=1);

namespace Tests\Larastan\Rules;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Requires Eloquent models to be tenant-scoped or explicitly opted out.
 *
 * @implements Rule<Node>
 */
final class UntenantedRule implements Rule
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
        if (! $node instanceof Class_) {
            return [];
        }

        if (! $this->isProductionModelPath($scope->getFile()) || ! $this->looksLikeEloquentModel($node)) {
            return [];
        }

        if ($this->hasUntenantedAttribute($node) || $this->declaresTenantIdProperty($node)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'Eloquent models must declare a tenant_id column contract or opt out with #[Untenanted(reason: ...)]. RackLab tenant isolation depends on explicit tenant ownership.'
            )->build(),
        ];
    }

    private function isProductionModelPath(string $filename): bool
    {
        $normalised = str_replace('\\', '/', $filename);

        return str_contains($normalised, '/app/Models/')
            || preg_match('#/packages/racklab/[^/]+/src/Models/#', $normalised) === 1;
    }

    private function looksLikeEloquentModel(Class_ $class): bool
    {
        if (! $class->extends instanceof Name) {
            return false;
        }

        $baseName = $class->extends->getLast();

        return in_array($baseName, ['Authenticatable', 'Model'], strict: true);
    }

    private function hasUntenantedAttribute(Class_ $class): bool
    {
        foreach ($class->attrGroups as $attributeGroup) {
            foreach ($attributeGroup->attrs as $attribute) {
                if ($attribute->name->getLast() === 'Untenanted') {
                    return true;
                }
            }
        }

        return false;
    }

    private function declaresTenantIdProperty(Class_ $class): bool
    {
        $docComment = $class->getDocComment();

        return $docComment instanceof \PhpParser\Comment\Doc
            && preg_match('/@property(?:-read|-write)?\s+[^\n]*\$tenant_id\b/', $docComment->getText()) === 1;
    }
}
