<?php

declare(strict_types=1);

namespace App\Courses;

/**
 * Outcome of a roster import: how many were enrolled now, how many were already
 * enrolled, which emails are pending an SSO account, and which had no account
 * (sign-in-only mode).
 */
final readonly class CourseRosterImportResult
{
    /**
     * @param  list<string>  $pending
     * @param  list<string>  $missing
     */
    public function __construct(
        public int $enrolled,
        public int $alreadyEnrolled,
        public array $pending,
        public array $missing,
    ) {}
}
