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
        Schema::create('provider_tasks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('deployment_id')->constrained('deployments')->cascadeOnDelete();
            $table->foreignUlid('deployment_operation_id')->constrained('deployment_operations')->cascadeOnDelete();
            $table->string('provider')->default('fake');
            $table->string('provider_task_id');
            $table->string('action');
            $table->string('state')->default('pending');
            $table->unsignedInteger('attempts')->default(1);
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_task_id']);
            $table->index(['tenant_id', 'state']);
            $table->index(['tenant_id', 'deployment_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('provider_tasks');
    }
};
