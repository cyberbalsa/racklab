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
        Schema::table('provider_tasks', function (Blueprint $table): void {
            $table->text('upid')->nullable()->after('provider_task_id');
            $table->string('proxmox_node')->nullable()->after('upid');
            $table->unsignedInteger('proxmox_pid')->nullable()->after('proxmox_node');
            $table->string('proxmox_pstart')->nullable()->after('proxmox_pid');
            $table->unsignedInteger('proxmox_starttime')->nullable()->after('proxmox_pstart');
            $table->string('proxmox_type')->nullable()->after('proxmox_starttime');
            $table->string('proxmox_vm_id')->nullable()->after('proxmox_type');
            $table->string('proxmox_user')->nullable()->after('proxmox_vm_id');
            $table->string('idempotency_key')->nullable()->after('proxmox_user');
            $table->string('operation_class')->nullable()->after('idempotency_key');
            $table->timestamp('lease_expires_at')->nullable()->after('operation_class');
            $table->unsignedInteger('attempt_count')->default(1)->after('lease_expires_at');
            $table->timestamp('last_polled_at')->nullable()->after('attempt_count');
            $table->timestamp('deadline_at')->nullable()->after('last_polled_at');

            $table->unique(['provider', 'idempotency_key']);
            $table->index(['provider', 'proxmox_node', 'state']);
            $table->index(['tenant_id', 'last_polled_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('provider_tasks', function (Blueprint $table): void {
            $table->dropUnique(['provider', 'idempotency_key']);
            $table->dropIndex(['provider', 'proxmox_node', 'state']);
            $table->dropIndex(['tenant_id', 'last_polled_at']);
            $table->dropColumn([
                'upid',
                'proxmox_node',
                'proxmox_pid',
                'proxmox_pstart',
                'proxmox_starttime',
                'proxmox_type',
                'proxmox_vm_id',
                'proxmox_user',
                'idempotency_key',
                'operation_class',
                'lease_expires_at',
                'attempt_count',
                'last_polled_at',
                'deadline_at',
            ]);
        });
    }
};
