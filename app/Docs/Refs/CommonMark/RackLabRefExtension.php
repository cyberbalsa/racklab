<?php

declare(strict_types=1);

namespace App\Docs\Refs\CommonMark;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\ExtensionInterface;

/**
 * Wires the RackLab `[[kind:id]]` inline parser + renderer into a
 * CommonMark environment. Registered alongside CommonMark core +
 * GFM by `App\Docs\MarkdownRenderer`.
 */
final readonly class RackLabRefExtension implements ExtensionInterface
{
    public function register(EnvironmentBuilderInterface $environment): void
    {
        // Priority 100 sits above CloseBracketParser (30) +
        // OpenBracketParser (20) so `[[kind:id]]` is consumed as a
        // ref before CommonMark's link grammar interprets the
        // brackets as a (broken) link.
        $environment->addInlineParser(new RackLabRefInlineParser, 100);
        $environment->addRenderer(RackLabRefInline::class, new RackLabRefRenderer);
    }
}
