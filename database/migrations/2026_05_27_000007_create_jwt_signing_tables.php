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
        Schema::create('signing_keys', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('kid')->unique();
            $table->string('algorithm')->default('RS256');
            $table->string('status')->default('current');
            $table->text('public_key_pem');
            $table->text('private_key_pem')->nullable();
            $table->timestamp('not_before')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'revoked_at']);
        });

        Schema::create('jwt_revocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('jti')->unique();
            $table->foreignId('revoked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jwt_revocations');
        Schema::dropIfExists('signing_keys');
    }
};
