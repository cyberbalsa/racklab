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
        Schema::create('quota_limits', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('scope_type');
            $table->string('scope_id');
            $table->string('dimension');
            $table->unsignedInteger('limit_value');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'scope_type', 'scope_id', 'dimension']);
            $table->index(['tenant_id', 'dimension']);
        });

        Schema::create('quota_reservations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('quota_limit_id')->nullable()->constrained('quota_limits')->nullOnDelete();
            $table->foreignUlid('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignUlid('deployment_id')->nullable()->constrained('deployments')->nullOnDelete();
            $table->foreignUlid('deployment_operation_id')->nullable()->constrained('deployment_operations')->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('scope_type');
            $table->string('scope_id');
            $table->string('dimension');
            $table->unsignedInteger('quantity');
            $table->string('state')->default('reserved');
            $table->timestamp('expires_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'dimension', 'state']);
            $table->index(['project_id', 'dimension', 'state']);
            $table->index(['deployment_id', 'state']);
            $table->index(['deployment_operation_id', 'state']);
        });

        Schema::create('quota_usages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('quota_limit_id')->nullable()->constrained('quota_limits')->nullOnDelete();
            $table->foreignUlid('quota_reservation_id')->nullable()->constrained('quota_reservations')->nullOnDelete();
            $table->foreignUlid('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignUlid('deployment_id')->nullable()->constrained('deployments')->nullOnDelete();
            $table->foreignUlid('deployment_operation_id')->nullable()->constrained('deployment_operations')->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('scope_type');
            $table->string('scope_id');
            $table->string('dimension');
            $table->unsignedInteger('quantity');
            $table->string('state')->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'dimension', 'state']);
            $table->index(['project_id', 'dimension', 'state']);
            $table->index(['deployment_id', 'state']);
            $table->index(['deployment_operation_id', 'state']);
        });

        Schema::create('quota_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('event_type');
            $table->string('result');
            $table->string('scope_type')->nullable();
            $table->string('scope_id')->nullable();
            $table->string('dimension')->nullable();
            $table->unsignedInteger('quantity')->nullable();
            $table->unsignedInteger('limit_value')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignUlid('deployment_id')->nullable()->constrained('deployments')->nullOnDelete();
            $table->foreignUlid('deployment_operation_id')->nullable()->constrained('deployment_operations')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['tenant_id', 'event_type', 'result']);
            $table->index(['tenant_id', 'dimension']);
            $table->index(['deployment_id', 'event_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quota_events');
        Schema::dropIfExists('quota_usages');
        Schema::dropIfExists('quota_reservations');
        Schema::dropIfExists('quota_limits');
    }
};
