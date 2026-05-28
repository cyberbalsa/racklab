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
        Schema::create('artifacts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('kind');
            $table->string('content_type');
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('storage_disk');
            $table->string('storage_path');
            $table->boolean('quarantined')->default(true);
            $table->string('owner_scope_type')->nullable();
            $table->string('owner_scope_id')->nullable();
            $table->string('rbac_visibility')->default('actor_only');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'kind']);
            $table->index(['tenant_id', 'owner_scope_type', 'owner_scope_id']);
            $table->index('sha256');
        });

        Schema::create('artifact_references', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('artifact_id')->constrained('artifacts')->cascadeOnDelete();
            $table->string('reference_type');
            $table->string('reference_id');
            $table->string('purpose');
            $table->timestamps();

            $table->index(['tenant_id', 'reference_type', 'reference_id']);
            $table->index(['artifact_id', 'purpose']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('artifact_references');
        Schema::dropIfExists('artifacts');
    }
};
