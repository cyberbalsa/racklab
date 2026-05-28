<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make audit_events.actor_tenant nullable so anonymous denial paths can
 * write rows. The original schema marked the column NOT NULL with a FK
 * constraint; that meant unauthenticated /horizon probes (which RackLab
 * audits) could not be persisted.
 *
 * `down()` is a deliberate no-op — after this migration runs, rows with
 * NULL actor_tenant may exist; re-imposing NOT NULL would fail. Audit
 * retention is append-only by design.
 *
 * See docs/superpowers/specs/2026-05-28-horizon-and-supply-chain-design.md §6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropForeign(['actor_tenant']);
            $table->foreignUlid('actor_tenant')->nullable()->change();
            $table->foreign('actor_tenant')->references('id')->on('tenants')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Deliberate no-op: rows with NULL actor_tenant may exist after up().
    }
};
