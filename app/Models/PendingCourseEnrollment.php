<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A roster enrolment recorded by email before the user has an account. When SSO
 * is enabled an instructor can enrol students who haven't logged in yet; the
 * pending row is converted to a CourseMembership when that user first provisions
 * (see PersonalProjectProvisioner).
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $course_id
 * @property string $email
 * @property string $role
 */
#[Fillable(['tenant_id', 'course_id', 'email', 'role'])]
class PendingCourseEnrollment extends Model
{
    use BelongsToTenant;
}
