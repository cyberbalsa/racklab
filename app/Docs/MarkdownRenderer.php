<?php

declare(strict_types=1);

namespace App\Docs;

/**
 * Minimal Markdown → HTML renderer for the S1/S2 docs slice.
 *
 * M8 S3 swaps this in for `league/commonmark` per PRD §15: full GFM
 * support, `racklabRef` cross-link parsing, and HTML sanitization. The
 * S1 implementation only handles paragraph wrapping + escapes so the
 * data model + API can ship without pulling commonmark before the
 * TipTap spike.
 *
 * Stable contract: render(string) → string. Callers must treat the
 * output as already-HTML-escaped; the upgrade in S3 keeps the same
 * signature so the API layer is unaffected.
 */
final readonly class MarkdownRenderer
{
    public function render(string $markdown): string
    {
        $normalized = preg_replace("/\r\n?|\r/", "\n", $markdown) ?? $markdown;
        $paragraphs = array_filter(array_map(trim(...), explode("\n\n", $normalized)));

        $html = [];
        foreach ($paragraphs as $paragraph) {
            $html[] = '<p>'.htmlspecialchars(str_replace("\n", ' ', $paragraph), ENT_QUOTES | ENT_HTML5, 'UTF-8').'</p>';
        }

        return implode("\n", $html);
    }
}
