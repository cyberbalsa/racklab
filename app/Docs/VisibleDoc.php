<?php

declare(strict_types=1);

namespace App\Docs;

use App\Models\Doc;

/**
 * A doc the actor may read, with the actor's edit capability resolved so
 * the index can show an Edit affordance without a second policy pass.
 */
final readonly class VisibleDoc
{
    public function __construct(
        public Doc $doc,
        public bool $canEdit,
    ) {}
}
