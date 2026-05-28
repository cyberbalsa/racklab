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
        Schema::create('token_grants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('revoked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('sanctum_token_id')->nullable()->unique();
            $table->string('jti')->nullable()->unique();
            $table->string('name');
            $table->string('track')->default('pat');
            $table->string('scope_type')->default('tenant_local');
            $table->json('tenant_set')->nullable();
            $table->string('resource_type');
            $table->string('resource_id');
            $table->json('abilities');
            $table->json('allowed_ip_cidrs')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign('sanctum_token_id')
                ->references('id')
                ->on('personal_access_tokens')
                ->nullOnDelete();
            $table->index(['tenant_id', 'owner_user_id']);
            $table->index(['resource_type', 'resource_id']);
            $table->index('revoked_at');
        });

        Schema::create('token_revocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('token_grant_id')->constrained('token_grants')->cascadeOnDelete();
            $table->foreignId('revoked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason');
            $table->timestamp('revoked_at');
            $table->timestamps();

            $table->index(['tenant_id', 'token_grant_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('token_revocations');
        Schema::dropIfExists('token_grants');
    }
};
