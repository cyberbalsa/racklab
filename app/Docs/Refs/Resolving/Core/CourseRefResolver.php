<?php

declare(strict_types=1);

namespace App\Docs\Refs\Resolving\Core;

use App\Docs\Refs\Resolving\RefResolutionContext;
use App\Docs\Refs\Resolving\RefResolver;
use App\Docs\Refs\Resolving\ResolvedRef;
use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Models\Course;

/**
 * Core resolver for `[[course:id]]` cross-links. Courses have no
 * lifecycle state, so the pill carries a label and link but no detail.
 */
final readonly class CourseRefResolver implements RefResolver
{
    public function __construct(private AccessResolver $access) {}

    public function kind(): string
    {
        return 'course';
    }

    public function resolve(RefResolutionContext $context, string $id): ResolvedRef
    {
        /** @var Course|null $course */
        $course = Course::query()->whereKey($id)->first();

        if (! $course instanceof Course) {
            return ResolvedRef::notFound($this->kind(), $id);
        }

        $decision = $this->access->permitted(
            $context->actor,
            new Permission('course.read'),
            $course,
            $context->tenant,
        );

        if (! $decision->allowed) {
            return ResolvedRef::redacted($this->kind(), $id);
        }

        // No public course detail page yet (M10a); render a non-link pill.
        return ResolvedRef::resolved($this->kind(), $id, $course->name, null, null);
    }
}
