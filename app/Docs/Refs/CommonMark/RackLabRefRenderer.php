<?php

declare(strict_types=1);

namespace App\Docs\Refs\CommonMark;

use InvalidArgumentException;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;

/**
 * Renders a `RackLabRefInline` to a `<racklab-ref>` custom element.
 *
 * The element starts in a "pending" state with the literal source
 * text inside (`[[kind:id]]`) so unsupported clients (and the
 * pre-hydration server-rendered HTML cache) still display
 * something useful. The JS island (`resources/js/islands/
 * racklab-ref.ts`, lands in M8 S5 with TipTap) upgrades the
 * element by polling the resolver endpoint with RBAC redaction.
 */
final class RackLabRefRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): HtmlElement
    {
        if (! $node instanceof RackLabRefInline) {
            throw new InvalidArgumentException('Renderer expects RackLabRefInline.');
        }

        return new HtmlElement('racklab-ref', [
            'data-kind' => $node->ref->kind,
            'data-id' => $node->ref->id,
            'class' => 'racklab-ref racklab-ref--pending',
        ], $node->ref->toSourceSyntax());
    }
}
