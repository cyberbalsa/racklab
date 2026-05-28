<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Auth\Tokens\CurrentTokenAbilities;
use App\Courses\VisibleCourseList;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CourseIndexController extends Controller
{
    public function __invoke(
        Request $request,
        TenantContextStore $tenantContext,
        VisibleCourseList $courses,
        CurrentTokenAbilities $tokenAbilities,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $context = $tenantContext->current();

        if (! $context instanceof TenantContext) {
            throw new NotFoundHttpException('Tenant context not found.');
        }

        if (! $tokenAbilities->allows($request, 'course.read')) {
            throw new AuthorizationException('The current token does not include course.read.');
        }

        return response()->json([
            'data' => array_map(
                static fn (Course $course): array => [
                    'id' => $course->getKey(),
                    'name' => $course->name,
                    'slug' => $course->slug,
                    'tenant_id' => $course->tenant_id,
                    'description' => $course->description,
                    'sharing_scope' => $course->sharing_scope,
                ],
                $courses->forUser($user, $context),
            ),
        ]);
    }
}
