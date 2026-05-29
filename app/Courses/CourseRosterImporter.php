<?php

declare(strict_types=1);

namespace App\Courses;

use App\Models\Course;
use App\Models\CourseMembership;
use App\Models\PendingCourseEnrollment;
use App\Models\User;

/**
 * Bulk-enrols a newline-separated list of student emails into a course.
 *
 * - An email with an existing account is enrolled immediately (idempotent).
 * - An email with no account: when SSO is enabled it becomes a pending
 *   enrolment (resolved on first login by PersonalProjectProvisioner); in
 *   sign-in-only mode it is reported as missing so staff can chase it up.
 */
final readonly class CourseRosterImporter
{
    private const string DEFAULT_ROLE = 'student';

    public function import(Course $course, string $tenantId, string $raw, bool $ssoEnabled): CourseRosterImportResult
    {
        $enrolled = 0;
        $alreadyEnrolled = 0;
        $pending = [];
        $missing = [];

        foreach ($this->emails($raw) as $email) {
            /** @var User|null $user */
            $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

            if ($user instanceof User) {
                $membership = CourseMembership::query()->firstOrCreate(
                    ['tenant_id' => $tenantId, 'course_id' => $course->getKey(), 'user_id' => $user->id],
                    ['role' => self::DEFAULT_ROLE],
                );

                if ($membership->wasRecentlyCreated) {
                    $enrolled++;
                } else {
                    $alreadyEnrolled++;
                }

                continue;
            }

            if ($ssoEnabled) {
                PendingCourseEnrollment::query()->firstOrCreate(
                    ['tenant_id' => $tenantId, 'course_id' => $course->getKey(), 'email' => $email],
                    ['role' => self::DEFAULT_ROLE],
                );
                $pending[] = $email;

                continue;
            }

            $missing[] = $email;
        }

        return new CourseRosterImportResult($enrolled, $alreadyEnrolled, $pending, $missing);
    }

    /**
     * Normalize the raw textarea into a de-duplicated list of lower-cased
     * emails (newline- or comma-separated, blanks dropped).
     *
     * @return list<string>
     */
    private function emails(string $raw): array
    {
        $emails = [];

        foreach (preg_split('/[\r\n,]+/', $raw) ?: [] as $line) {
            $email = mb_strtolower(trim($line));

            if ($email !== '' && ! in_array($email, $emails, strict: true)) {
                $emails[] = $email;
            }
        }

        return $emails;
    }
}
