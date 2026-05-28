<?php

declare(strict_types=1);

namespace App\Docs;

use App\Docs\Refs\CommonMark\RackLabRefExtension;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Renders authored Markdown to the HTML cache that's persisted in
 * `doc_versions.html_cache` and served to readers.
 *
 * Pipeline: CommonMark core + GFM (tables, strikethrough,
 * task lists, autolinks) + the `[[kind:id]]` extension that emits
 * `<racklab-ref>` custom elements. HTML in the source is escaped
 * via the `html_input: 'escape'` environment setting, so authored
 * Markdown cannot inject scripts even before the editor's
 * sanitization pass. `allow_unsafe_links: false` blocks the
 * `javascript:` / `data:` protocols inside links.
 *
 * Configuration is immutable per render — the converter is built
 * lazily on first render and cached for the lifetime of the
 * service instance.
 */
class MarkdownRenderer
{
    private ?MarkdownConverter $converter = null;

    public function render(string $markdown): string
    {
        $converter = $this->converter ??= $this->buildConverter();

        return (string) $converter->convert($markdown);
    }

    protected function buildEnvironment(): Environment
    {
        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'renderer' => [
                'soft_break' => "\n",
            ],
        ]);

        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);
        $environment->addExtension(new RackLabRefExtension);

        return $environment;
    }

    private function buildConverter(): MarkdownConverter
    {
        return new MarkdownConverter($this->buildEnvironment());
    }
}
