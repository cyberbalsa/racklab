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
        Schema::create('catalog_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('sharing_scope')->default('tenant_local');
            $table->json('shared_with_tenants')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'name']);
        });

        Schema::create('catalog_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('catalog_item_id')->constrained('catalog_items')->cascadeOnDelete();
            $table->foreignUlid('stack_definition_id')->constrained('stack_definitions')->restrictOnDelete();
            $table->string('version');
            $table->string('state')->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->text('summary')->nullable();
            $table->timestamps();

            $table->unique(['catalog_item_id', 'version']);
            $table->index(['tenant_id', 'state', 'published_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalog_versions');
        Schema::dropIfExists('catalog_items');
    }
};
