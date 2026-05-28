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
        Schema::create('project_ssh_keys', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('key_type');
            $table->text('public_key');
            $table->string('fingerprint');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'fingerprint']);
            $table->index(['tenant_id', 'project_id']);
        });

        Schema::create('host_key_phone_home_tokens', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('deployment_id')->constrained('deployments')->cascadeOnDelete();
            $table->foreignUlid('deployment_resource_id')->nullable()->constrained('deployment_resources')->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'deployment_id']);
            $table->index(['token_hash', 'used_at']);
        });

        Schema::create('deployment_host_keys', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('deployment_id')->constrained('deployments')->cascadeOnDelete();
            $table->foreignUlid('deployment_resource_id')->nullable()->constrained('deployment_resources')->nullOnDelete();
            $table->string('key_type');
            $table->text('public_key');
            $table->string('fingerprint');
            $table->timestamp('first_seen_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['deployment_id', 'deployment_resource_id', 'fingerprint']);
            $table->index(['tenant_id', 'deployment_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deployment_host_keys');
        Schema::dropIfExists('host_key_phone_home_tokens');
        Schema::dropIfExists('project_ssh_keys');
    }
};
