<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $usesPostgres = DB::getDriverName() === 'pgsql';

        Schema::create('audit_events', function (Blueprint $table) use ($usesPostgres) {
            $table->id();
            $table->string('event_type');
            $table->string('action');
            $table->string('result');
            $table->string('actor_type');
            $table->string('actor_id');
            $table->foreignUlid('actor_tenant')->constrained('tenants')->restrictOnDelete();
            $table->string('resource_type');
            $table->string('resource_id')->nullable();
            $table->foreignUlid('resource_tenant')->nullable()->constrained('tenants')->restrictOnDelete();
            if ($usesPostgres) {
                $table->jsonb('target_tenant_set');
                $table->jsonb('effective_permissions')->nullable();
            } else {
                $table->json('target_tenant_set');
                $table->json('effective_permissions')->nullable();
            }
            $table->string('request_id')->nullable();
            $table->string('correlation_id')->nullable();
            $table->string('source_ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            if ($usesPostgres) {
                $table->jsonb('metadata')->nullable();
            } else {
                $table->json('metadata')->nullable();
            }
            $table->timestamp('occurred_at');
            $table->char('prev_hash', 64)->nullable();
            $table->char('hash', 64);
            $table->timestamps();

            $table->index('actor_tenant');
            $table->index('resource_tenant');
            $table->index(['event_type', 'result']);
            $table->index('correlation_id');
        });

        if ($usesPostgres) {
            DB::statement('CREATE INDEX audit_events_target_tenant_set_gin ON audit_events USING GIN (target_tenant_set)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS audit_events_target_tenant_set_gin');
        }

        Schema::dropIfExists('audit_events');
    }
};
