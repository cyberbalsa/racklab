<?php

declare(strict_types=1);

namespace App\Docs\Refs\CommonMark;

use App\Docs\Refs\RackLabRef;
use League\CommonMark\Node\Inline\AbstractInline;

/**
 * CommonMark inline node representing a parsed `[[kind:id]]` ref.
 *
 * The renderer (`RackLabRefRenderer`) emits the canonical HTML
 * placeholder. Client-side JS upgrades it to a status pill on
 * page mount via the RBAC-checked resolver endpoint.
 */
final class RackLabRefInline extends AbstractInline
{
    public function __construct(public readonly RackLabRef $ref) {}
}
