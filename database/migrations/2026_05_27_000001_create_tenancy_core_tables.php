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
        Schema::create('tenants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('tenant_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
            $table->index(['user_id', 'is_primary']);
        });

        Schema::create('role_bindings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('principal_type');
            $table->string('principal_id');
            $table->string('role');
            $table->string('resource_type');
            $table->string('resource_id');
            $table->string('scope_type');
            $table->foreignUlid('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete();
            $table->json('tenant_set')->nullable();
            $table->foreignId('granted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('granted_reason')->nullable();
            $table->timestamps();

            $table->index(['principal_type', 'principal_id']);
            $table->index(['resource_type', 'resource_id']);
            $table->index(['scope_type', 'tenant_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_bindings');
        Schema::dropIfExists('tenant_memberships');
        Schema::dropIfExists('tenants');
    }
};
