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
        Schema::create('plugin_installations', function (Blueprint $table): void {
            $table->string('slug')->primary();
            $table->string('package_name');
            $table->string('version');
            $table->string('state');
            $table->string('service_provider');
            $table->string('manifest_class')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamp('installed_at');
            $table->timestamp('migrated_at')->nullable();
            $table->timestamp('enabled_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();

            $table->index('state');
        });

        Schema::create('plugin_migration_records', function (Blueprint $table): void {
            $table->id();
            $table->string('plugin_slug');
            $table->string('direction');
            $table->string('migration_version')->nullable();
            $table->timestamp('executed_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['plugin_slug', 'direction']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plugin_migration_records');
        Schema::dropIfExists('plugin_installations');
    }
};
