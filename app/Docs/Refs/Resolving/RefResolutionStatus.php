<?php

declare(strict_types=1);

namespace App\Docs\Refs\Resolving;

/**
 * Outcome of resolving a single `[[kind:id]]` cross-link reference.
 *
 * The JS status-pill island styles itself from this value:
 *  - Resolved: the actor may see the target — render label + detail + link.
 *  - Redacted: the target exists but the actor lacks read access —
 *    render "redacted reference" with the kind only.
 *  - NotFound: no such target in the active tenant — render "missing".
 *  - Unsupported: no resolver is registered for this kind.
 */
enum RefResolutionStatus: string
{
    case Resolved = 'resolved';
    case Redacted = 'redacted';
    case NotFound = 'not_found';
    case Unsupported = 'unsupported';
}
