<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vpn_public_ip_pools', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('provider')->default('openvpn');
            $table->string('cidr');
            $table->unsignedInteger('port_range_min')->default(20000);
            $table->unsignedInteger('port_range_max')->default(29999);
            $table->json('metadata')->nullable();
            $table->string('sharing_scope')->default('tenant_local');
            $table->json('shared_with_tenants')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'provider']);
        });

        Schema::create('network_vpn_endpoints', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignUlid('deployment_id')->nullable()->constrained('deployments')->nullOnDelete();
            $table->foreignUlid('network_id')->constrained('networks')->cascadeOnDelete();
            $table->foreignUlid('vpn_public_ip_pool_id')->constrained('vpn_public_ip_pools')->restrictOnDelete();
            $table->string('name');
            $table->string('state')->default('pending');
            $table->string('provider')->default('openvpn');
            $table->string('capability')->default('network:vpnaas:openvpn:v1');
            $table->json('metadata')->nullable();
            $table->string('sharing_scope')->default('tenant_local');
            $table->json('shared_with_tenants')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'project_id', 'state']);
            $table->index(['tenant_id', 'network_id']);
            $table->index(['tenant_id', 'deployment_id']);
        });

        Schema::create('network_vpn_endpoint_bindings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('network_vpn_endpoint_id')->constrained('network_vpn_endpoints')->cascadeOnDelete();
            $table->string('node')->nullable();
            $table->string('public_ip');
            $table->unsignedInteger('udp_port');
            $table->string('state')->default('pending');
            $table->json('provider_binding')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['public_ip', 'udp_port']);
            $table->index(['network_vpn_endpoint_id', 'state']);
            $table->index(['tenant_id', 'node']);
        });

        Schema::create('vpn_client_profiles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('network_vpn_endpoint_id')->constrained('network_vpn_endpoints')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('common_name');
            $table->binary('config_ciphertext'); // Laravel Crypt of the rendered .ovpn payload
            $table->binary('private_key_ciphertext'); // separate so we can wipe key but keep config metadata
            $table->binary('certificate_pem')->nullable();
            $table->string('state')->default('active');
            $table->foreignId('revoked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoked_reason')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamps();

            $table->unique(['network_vpn_endpoint_id', 'user_id'], 'vpn_profile_per_user');
            $table->index(['tenant_id', 'user_id', 'state']);
            $table->index(['network_vpn_endpoint_id', 'state']);
        });

        Schema::create('vpn_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('vpn_client_profile_id')->constrained('vpn_client_profiles')->cascadeOnDelete();
            $table->foreignUlid('network_vpn_endpoint_id')->constrained('network_vpn_endpoints')->cascadeOnDelete();
            $table->string('peer_ip')->nullable();
            $table->string('state')->default('active');
            $table->unsignedBigInteger('bytes_in')->default(0);
            $table->unsignedBigInteger('bytes_out')->default(0);
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->string('disconnect_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'vpn_client_profile_id', 'state']);
            $table->index(['network_vpn_endpoint_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vpn_sessions');
        Schema::dropIfExists('vpn_client_profiles');
        Schema::dropIfExists('network_vpn_endpoint_bindings');
        Schema::dropIfExists('network_vpn_endpoints');
        Schema::dropIfExists('vpn_public_ip_pools');
    }
};
