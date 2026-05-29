<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_course_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->string('course_id');
            $table->string('email');
            $table->string('role')->default('student');
            $table->timestamps();

            $table->unique(['tenant_id', 'course_id', 'email']);
            $table->index(['tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_course_enrollments');
    }
};
