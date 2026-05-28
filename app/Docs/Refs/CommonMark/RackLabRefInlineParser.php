<?php

declare(strict_types=1);

namespace App\Docs\Refs\CommonMark;

use App\Docs\Refs\RackLabRef;
use InvalidArgumentException;
use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;

/**
 * CommonMark inline parser that matches the `[[kind:id]]` syntax
 * inside paragraphs, list items, and other inline contexts.
 *
 * Matches inside fenced code blocks and inline-code spans are
 * passed through unparsed by CommonMark itself — those are
 * verbatim contexts in the spec, so the renderer is automatically
 * Markdown-compliant on that front.
 */
final class RackLabRefInlineParser implements InlineParserInterface
{
    private const string REGEX = '\[\[([a-z][a-z0-9_]{1,31}):([A-Za-z0-9_\-]{1,64})\]\]';

    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::regex(self::REGEX)->caseSensitive();
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        $matches = $inlineContext->getSubMatches();

        if (count($matches) < 2) {
            return false;
        }

        try {
            $ref = new RackLabRef(kind: $matches[0], id: $matches[1]);
        } catch (InvalidArgumentException) {
            // Should be unreachable — the regex already enforces the
            // same character set. Defensive return so a malformed
            // grammar evolution doesn't crash the renderer.
            return false;
        }

        $inlineContext->getCursor()->advanceBy(strlen($inlineContext->getFullMatch()));
        $inlineContext->getContainer()->appendChild(new RackLabRefInline($ref));

        return true;
    }
}
