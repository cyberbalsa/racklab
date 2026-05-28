<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('docs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignUlid('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('slug');
            $table->string('title');
            $table->foreignUlid('current_version_id')->nullable();
            $table->string('sharing_scope')->default('tenant_local');
            $table->json('shared_with_tenants')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'project_id']);
            $table->index(['tenant_id', 'course_id']);
            $table->index(['tenant_id', 'owner_user_id']);
        });

        Schema::create('doc_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('doc_id')->constrained('docs')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->longText('markdown_source');
            $table->longText('html_cache')->nullable();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('editor_message')->nullable();
            $table->timestamps();

            $table->unique(['doc_id', 'version_number']);
            $table->index(['tenant_id', 'doc_id']);
        });

        Schema::create('doc_images', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('doc_id')->constrained('docs')->cascadeOnDelete();
            $table->foreignUlid('artifact_id')->nullable()->constrained('artifacts')->nullOnDelete();
            $table->string('content_type');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('sha256')->nullable();
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'doc_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doc_images');
        Schema::dropIfExists('doc_versions');
        Schema::dropIfExists('docs');
    }
};
