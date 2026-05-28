<?php

declare(strict_types=1);

namespace App\Courses;

use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Models\Course;
use App\Models\User;

final readonly class VisibleCourseList
{
    public function __construct(private AccessResolver $accessResolver) {}

    /**
     * @return list<Course>
     */
    public function forUser(User $user, TenantContext $context): array
    {
        $actor = new ActorIdentity((string) $user->id);
        $permission = new Permission('course.read');
        $visible = [];

        /** @var Course $course */
        foreach (Course::query()->orderBy('name')->orderBy('id')->get() as $course) {
            $decision = $this->accessResolver->permitted($actor, $permission, $course, $context);

            if ($decision->allowed) {
                $visible[] = $course;
            }
        }

        return $visible;
    }
}
