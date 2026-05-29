<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $table): void {
            // Optional association to the course a deployment was created for.
            // Course staff manage course-associated deployments only (never a
            // member's unrelated/personal deployments). Cascades to null if the
            // course is deleted so the deployment survives.
            $table->string('course_id')->nullable()->after('project_id');
            $table->index(['course_id']);
            $table->foreign('course_id')->references('id')->on('courses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table): void {
            $table->dropForeign(['course_id']);
            $table->dropColumn('course_id');
        });
    }
};
