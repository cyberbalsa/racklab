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
        Schema::create('stack_definitions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('project_id')->nullable()->constrained('projects')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('scope')->default('project_local');
            $table->boolean('is_reserved_default')->default(false);
            $table->json('definition')->nullable();
            $table->string('sharing_scope')->default('tenant_local');
            $table->json('shared_with_tenants')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'project_id', 'slug']);
            $table->index(['tenant_id', 'scope']);
        });

        Schema::create('project_default_stacks', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('project_id')->unique()->constrained('projects')->cascadeOnDelete();
            $table->foreignUlid('stack_definition_id')->constrained('stack_definitions')->restrictOnDelete();
            $table->ulid('active_deployment_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'stack_definition_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_default_stacks');
        Schema::dropIfExists('stack_definitions');
    }
};
