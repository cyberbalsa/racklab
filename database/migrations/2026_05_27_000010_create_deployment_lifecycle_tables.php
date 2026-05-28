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
        Schema::create('deployments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignUlid('stack_definition_id')->constrained('stack_definitions')->restrictOnDelete();
            $table->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('state')->default('pending');
            $table->string('provider')->default('fake');
            $table->timestamp('lease_expires_at')->nullable();
            $table->json('metadata')->nullable();
            $table->string('sharing_scope')->default('tenant_local');
            $table->json('shared_with_tenants')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'project_id', 'state']);
            $table->index(['tenant_id', 'stack_definition_id']);
        });

        Schema::create('deployment_resources', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('deployment_id')->constrained('deployments')->cascadeOnDelete();
            $table->string('component_key');
            $table->string('kind')->default('vm');
            $table->string('state')->default('pending');
            $table->string('provider')->default('fake');
            $table->string('provider_resource_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['deployment_id', 'component_key']);
            $table->index(['tenant_id', 'deployment_id', 'state']);
        });

        Schema::create('deployment_operations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('deployment_id')->constrained('deployments')->cascadeOnDelete();
            $table->foreignUlid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignUlid('stack_definition_id')->constrained('stack_definitions')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind');
            $table->string('idempotency_key');
            $table->string('state')->default('pending');
            $table->json('requested_diff')->nullable();
            $table->json('result')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'actor_user_id', 'idempotency_key']);
            $table->index(['tenant_id', 'project_id', 'state']);
        });

        Schema::create('deployment_state_transitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('deployment_id')->constrained('deployments')->cascadeOnDelete();
            $table->foreignUlid('deployment_operation_id')->nullable()->constrained('deployment_operations')->nullOnDelete();
            $table->string('from_state')->nullable();
            $table->string('to_state');
            $table->string('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'deployment_id']);
            $table->index(['tenant_id', 'to_state']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deployment_state_transitions');
        Schema::dropIfExists('deployment_operations');
        Schema::dropIfExists('deployment_resources');
        Schema::dropIfExists('deployments');
    }
};
