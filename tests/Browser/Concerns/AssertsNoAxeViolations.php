<?php

declare(strict_types=1);

namespace Tests\Browser\Concerns;

use Facebook\WebDriver\Exception\ScriptTimeoutException;
use Laravel\Dusk\Browser;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;

trait AssertsNoAxeViolations
{
    public function assertNoAxeViolations(Browser $browser): Browser
    {
        $axeSource = $this->axeCoreSource();

        $browser->script($axeSource);
        $browser->driver->manage()->timeouts()->setScriptTimeout(10);

        try {
            /** @var array{error?: string, violations?: list<array{id: string, impact: string|null, description: string, nodes: int}>} $result */
            $result = $browser->driver->executeAsyncScript(
                <<<'JS'
                    const callback = arguments[arguments.length - 1];

                    window.axe.run(document, {
                        runOnly: {
                            type: 'tag',
                            values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'],
                        },
                    }).then((results) => callback({
                        violations: results.violations.map((violation) => ({
                            id: violation.id,
                            impact: violation.impact,
                            description: violation.description,
                            nodes: violation.nodes.length,
                        })),
                    })).catch((error) => callback({
                        error: String(error),
                    }));
                JS,
            );
        } catch (ScriptTimeoutException $scriptTimeoutException) {
            throw new AssertionFailedError('axe-core did not finish within the Dusk script timeout.', 0, $scriptTimeoutException);
        }

        if (isset($result['error'])) {
            throw new AssertionFailedError('axe-core failed: '.$result['error']);
        }

        $violations = $result['violations'] ?? [];

        if ($violations !== []) {
            $summary = array_map(
                static fn (array $violation): string => sprintf(
                    '- %s [%s] %s (%d nodes)',
                    $violation['id'],
                    $violation['impact'] ?? 'unknown',
                    $violation['description'],
                    $violation['nodes'],
                ),
                $violations,
            );

            throw new AssertionFailedError(sprintf(
                "axe-core reported %d accessibility violation(s):\n%s",
                count($violations),
                implode("\n", $summary),
            ));
        }

        Assert::assertCount(0, $violations, 'axe-core reported no accessibility violations.');

        return $browser;
    }

    private function axeCoreSource(): string
    {
        $path = base_path('node_modules/axe-core/axe.min.js');

        if (! is_file($path)) {
            throw new AssertionFailedError('axe-core is not installed. Run npm install before browser tests.');
        }

        $source = file_get_contents($path);

        if ($source === false) {
            throw new AssertionFailedError('Unable to read axe-core source from '.$path);
        }

        return $source;
    }
}
