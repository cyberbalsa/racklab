<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('scripts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('runner_kind');
            $table->foreignUlid('current_version_id')->nullable();
            $table->string('state')->default('draft');
            $table->string('sharing_scope')->default('tenant_local');
            $table->json('shared_with_tenants')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'project_id', 'slug']);
            $table->index(['tenant_id', 'project_id', 'runner_kind']);
        });

        Schema::create('script_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('script_id')->constrained('scripts')->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version_number');
            $table->json('command');
            $table->longText('source');
            $table->char('executable_hash', 64);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['script_id', 'version_number']);
            $table->index(['tenant_id', 'script_id']);
        });

        Schema::create('script_approvals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('script_id')->constrained('scripts')->cascadeOnDelete();
            $table->foreignUlid('script_version_id')->constrained('script_versions')->cascadeOnDelete();
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('scope_type');
            $table->string('scope_id')->nullable();
            $table->string('state')->default('active');
            $table->timestamp('invalidated_at')->nullable();
            $table->text('invalidation_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'script_id', 'state']);
            $table->index(['scope_type', 'scope_id', 'state']);
        });

        Schema::table('script_runs', function (Blueprint $table): void {
            $table->foreignUlid('project_id')->nullable()->after('actor_user_id')->constrained('projects')->nullOnDelete();
            $table->foreignUlid('script_id')->nullable()->after('project_id')->constrained('scripts')->nullOnDelete();
            $table->foreignUlid('script_version_id')->nullable()->after('script_id')->constrained('script_versions')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('script_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('script_version_id');
            $table->dropConstrainedForeignId('script_id');
            $table->dropConstrainedForeignId('project_id');
        });

        Schema::dropIfExists('script_approvals');
        Schema::dropIfExists('script_versions');
        Schema::dropIfExists('scripts');
    }
};
