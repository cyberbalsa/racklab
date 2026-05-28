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
        Schema::create('provider_networks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('provider');
            $table->string('provider_cluster')->nullable();
            $table->string('network_type');
            $table->string('external_id');
            $table->string('bridge')->nullable();
            $table->unsignedInteger('vlan_tag')->nullable();
            $table->json('metadata')->nullable();
            $table->string('sharing_scope')->default('tenant_local');
            $table->json('shared_with_tenants')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
            $table->unique(['tenant_id', 'provider', 'provider_cluster', 'external_id'], 'provider_network_backend_unique');
            $table->index(['tenant_id', 'provider', 'network_type']);
        });

        Schema::create('network_offerings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('provider_network_id')->constrained('provider_networks')->restrictOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('offering_type');
            $table->string('reachability');
            $table->json('metadata')->nullable();
            $table->string('sharing_scope')->default('tenant_local');
            $table->json('shared_with_tenants')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'offering_type']);
            $table->index(['tenant_id', 'reachability']);
        });

        Schema::create('deployment_network_bindings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('deployment_id')->constrained('deployments')->cascadeOnDelete();
            $table->foreignUlid('deployment_resource_id')->constrained('deployment_resources')->cascadeOnDelete();
            $table->foreignUlid('network_offering_id')->constrained('network_offerings')->restrictOnDelete();
            $table->foreignUlid('provider_network_id')->constrained('provider_networks')->restrictOnDelete();
            $table->string('component_key');
            $table->string('nic_key');
            $table->string('reachability');
            $table->string('state')->default('attached');
            $table->string('provider');
            $table->json('provider_binding')->nullable();
            $table->string('management_host')->nullable();
            $table->unsignedInteger('management_port')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['deployment_resource_id', 'nic_key'], 'deployment_network_binding_resource_nic_unique');
            $table->index(['tenant_id', 'deployment_id']);
            $table->index(['tenant_id', 'reachability']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deployment_network_bindings');
        Schema::dropIfExists('network_offerings');
        Schema::dropIfExists('provider_networks');
    }
};
