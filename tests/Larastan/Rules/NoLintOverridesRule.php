<?php

declare(strict_types=1);

namespace Tests\Larastan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Forbids inline lint-override comments in production code per spec §8.
 *
 * Production code = anything under app/ or packages/racklab/{slug}/src/.
 * Forbidden comment patterns are declared in FORBIDDEN_PATTERNS.
 *
 * Test code in tests/ is allowed two narrow exceptions; see spec §8.
 *
 * @implements Rule<Node>
 */
final class NoLintOverridesRule implements Rule
{
    private const array FORBIDDEN_PATTERNS = [
        '@phpstan-ignore',
        '@psalm-suppress',
        '@phpcs:ignore',
        '@phpcs:disable',
        'eslint-disable',
        '@ts-ignore',
        '@ts-expect-error',
        '// noqa',
    ];

    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return array<int, RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $filename = $scope->getFile();

        if (! $this->isProductionPath($filename)) {
            return [];
        }

        $errors = [];

        foreach ($node->getComments() as $comment) {
            $text = $comment->getText();
            foreach (self::FORBIDDEN_PATTERNS as $pattern) {
                if (str_contains($text, $pattern)) {
                    $errors[] = RuleErrorBuilder::message(sprintf(
                        'Forbidden lint-override comment "%s" found in production code. Per spec §8 / PRD §17 no-overrides discipline, fix the underlying code or extend the rule — never silence the linter inline.',
                        $pattern
                    ))->line($comment->getStartLine())->build();
                }
            }
        }

        return $errors;
    }

    private function isProductionPath(string $filename): bool
    {
        $normalised = str_replace('\\', '/', $filename);

        return str_contains($normalised, '/app/')
            || preg_match('#/packages/racklab/[^/]+/src/#', $normalised) === 1;
    }
}
