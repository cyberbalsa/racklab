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
        Schema::create('subnet_pools', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('cidr');
            $table->unsignedTinyInteger('ip_version')->default(4);
            $table->unsignedTinyInteger('default_prefix_length');
            $table->unsignedTinyInteger('min_prefix_length');
            $table->unsignedTinyInteger('max_prefix_length');
            $table->json('metadata')->nullable();
            $table->string('sharing_scope')->default('tenant_local');
            $table->json('shared_with_tenants')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'ip_version']);
        });

        Schema::create('networks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignUlid('network_offering_id')->constrained('network_offerings')->restrictOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('state')->default('active');
            $table->string('provider');
            $table->string('reachability');
            $table->json('metadata')->nullable();
            $table->string('sharing_scope')->default('tenant_local');
            $table->json('shared_with_tenants')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'project_id', 'slug']);
            $table->index(['tenant_id', 'project_id', 'state']);
            $table->index(['tenant_id', 'network_offering_id']);
        });

        Schema::create('subnets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignUlid('network_id')->constrained('networks')->cascadeOnDelete();
            $table->foreignUlid('subnet_pool_id')->nullable()->constrained('subnet_pools')->nullOnDelete();
            $table->string('cidr');
            $table->unsignedTinyInteger('ip_version')->default(4);
            $table->string('gateway_ip')->nullable();
            $table->boolean('dhcp_enabled')->default(true);
            $table->json('allocation_pools')->nullable();
            $table->json('dns_nameservers')->nullable();
            $table->json('host_routes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'project_id', 'cidr']);
            $table->index(['tenant_id', 'network_id']);
        });

        Schema::create('routers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('state')->default('active');
            $table->string('provider');
            $table->string('provider_router_id')->nullable();
            $table->json('metadata')->nullable();
            $table->string('sharing_scope')->default('tenant_local');
            $table->json('shared_with_tenants')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'project_id', 'slug']);
            $table->index(['tenant_id', 'project_id', 'state']);
        });

        Schema::create('router_networks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('router_id')->constrained('routers')->cascadeOnDelete();
            $table->foreignUlid('network_id')->constrained('networks')->cascadeOnDelete();
            $table->foreignUlid('subnet_id')->nullable()->constrained('subnets')->nullOnDelete();
            $table->string('interface_ip')->nullable();
            $table->string('state')->default('active');
            $table->json('provider_binding')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['router_id', 'network_id']);
            $table->index(['tenant_id', 'router_id']);
            $table->index(['tenant_id', 'network_id']);
        });

        Schema::create('floating_ip_pools', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('provider_network_id')->constrained('provider_networks')->restrictOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('cidr');
            $table->unsignedTinyInteger('ip_version')->default(4);
            $table->string('provider');
            $table->json('metadata')->nullable();
            $table->string('sharing_scope')->default('tenant_local');
            $table->json('shared_with_tenants')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'provider']);
        });

        Schema::create('floating_ips', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignUlid('floating_ip_pool_id')->constrained('floating_ip_pools')->restrictOnDelete();
            $table->foreignUlid('deployment_network_binding_id')->nullable()->constrained('deployment_network_bindings')->nullOnDelete();
            $table->foreignId('allocated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('address');
            $table->string('state')->default('allocated');
            $table->string('provider');
            $table->json('provider_binding')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'project_id', 'state']);
            $table->index(['floating_ip_pool_id', 'address', 'state']);
            $table->index(['tenant_id', 'deployment_network_binding_id']);
        });

        Schema::create('security_groups', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('state')->default('active');
            $table->string('provider')->default('fake');
            $table->string('provider_security_group_id')->nullable();
            $table->json('metadata')->nullable();
            $table->string('sharing_scope')->default('tenant_local');
            $table->json('shared_with_tenants')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'project_id', 'slug']);
            $table->index(['tenant_id', 'project_id', 'state']);
        });

        Schema::create('security_group_rules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('security_group_id')->constrained('security_groups')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('direction');
            $table->string('protocol');
            $table->string('ethertype')->default('IPv4');
            $table->unsignedInteger('port_min')->nullable();
            $table->unsignedInteger('port_max')->nullable();
            $table->string('remote_cidr')->nullable();
            $table->string('state')->default('active');
            $table->string('provider_rule_id')->nullable();
            $table->json('provider_binding')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'security_group_id']);
            $table->index(['tenant_id', 'direction', 'protocol']);
        });

        Schema::create('provider_drifts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('provider');
            $table->string('resource_type');
            $table->string('resource_id');
            $table->string('resource_label')->nullable();
            $table->string('state')->default('detected');
            $table->json('expected_state');
            $table->json('observed_state');
            $table->json('drift');
            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolution')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'provider', 'state']);
            $table->index(['tenant_id', 'project_id', 'state']);
            $table->index(['resource_type', 'resource_id', 'state']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('provider_drifts');
        Schema::dropIfExists('security_group_rules');
        Schema::dropIfExists('security_groups');
        Schema::dropIfExists('floating_ips');
        Schema::dropIfExists('floating_ip_pools');
        Schema::dropIfExists('router_networks');
        Schema::dropIfExists('routers');
        Schema::dropIfExists('subnets');
        Schema::dropIfExists('networks');
        Schema::dropIfExists('subnet_pools');
    }
};
