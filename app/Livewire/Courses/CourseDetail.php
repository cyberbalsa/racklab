<?php

declare(strict_types=1);

namespace App\Livewire\Courses;

use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Models\Course;
use App\Models\CourseMembership;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Course dashboard Key Screen (PRD §15, instructor): a single course with its
 * roster (members + roles). Gated by `course.read` through AccessResolver (404
 * on denial, no existence leak). Students lack `course.read`, so this is an
 * instructor/TA/admin surface.
 */
final class CourseDetail extends Component
{
    public string $courseId = '';

    public function mount(string $course): void
    {
        $user = $this->currentUser();
        $context = $this->currentContext();

        $model = Course::query()->whereKey($course)->first();

        if (! $model instanceof Course || ! $this->canRead($user, $model, $context)) {
            throw new NotFoundHttpException('Course not found.');
        }

        $this->courseId = $model->id;
    }

    public function render(): View
    {
        $user = $this->currentUser();
        $context = $this->currentContext();

        $course = Course::query()->whereKey($this->courseId)->first();

        if (! $course instanceof Course || ! $this->canRead($user, $course, $context)) {
            throw new NotFoundHttpException('Course not found.');
        }

        return view('livewire.courses.course-detail', [
            'course' => $course,
            'members' => CourseMembership::query()
                ->where('course_id', $course->id)
                ->with('user')
                ->get()
                ->all(),
        ]);
    }

    private function canRead(User $user, Course $course, TenantContext $context): bool
    {
        return app(AccessResolver::class)->permitted(
            new ActorIdentity((string) $user->id),
            new Permission('course.read'),
            $course,
            $context,
        )->allowed;
    }

    private function currentUser(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }

    private function currentContext(): TenantContext
    {
        $context = app(TenantContextStore::class)->current();

        if (! $context instanceof TenantContext) {
            throw new NotFoundHttpException('Tenant context not found.');
        }

        return $context;
    }
}
