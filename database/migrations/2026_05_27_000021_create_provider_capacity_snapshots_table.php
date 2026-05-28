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
        Schema::create('provider_capacity_snapshots', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('provider');
            $table->string('provider_cluster')->nullable();
            $table->string('node');
            $table->boolean('healthy')->default(true);
            $table->boolean('maintenance_mode')->default(false);
            $table->unsignedInteger('available_vcpus')->default(0);
            $table->unsignedInteger('available_memory_mb')->default(0);
            $table->unsignedInteger('available_storage_gb')->default(0);
            $table->unsignedInteger('job_pressure')->default(0);
            $table->json('templates')->nullable();
            $table->json('tags')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'provider', 'provider_cluster', 'node'], 'provider_capacity_snapshot_unique');
            $table->index(['tenant_id', 'provider', 'healthy', 'maintenance_mode'], 'provider_capacity_snapshot_eligibility');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('provider_capacity_snapshots');
    }
};
