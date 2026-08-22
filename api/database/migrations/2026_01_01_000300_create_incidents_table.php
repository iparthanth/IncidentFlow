<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // A retired service must not erase the incidents that reference it;
            // history outlives the thing it happened to.
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();

            /** Human-facing identifier, e.g. INC-2026-0042. Stable and quotable. */
            $table->string('reference', 32);
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('impact')->nullable();

            $table->string('severity', 8);
            $table->string('status', 16);

            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('commander_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampTz('detected_at')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->timestampTz('mitigated_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('closed_at')->nullable();

            /**
             * Denormalised durations, written inside the same transaction as the
             * status change that produces them. Keeps MTTA/MTTR a plain AVG()
             * with no database-specific date arithmetic, and keeps the metric
             * stable even if timestamps are later corrected by an admin.
             */
            $table->unsignedInteger('time_to_acknowledge_seconds')->nullable();
            $table->unsignedInteger('time_to_resolve_seconds')->nullable();

            $table->string('source', 32)->default('web');
            $table->string('external_reference')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['organization_id', 'reference']);
            // Composite indexes ordered to serve the list view's default query:
            // filter by organization, then status/severity, sorted by recency.
            $table->index(['organization_id', 'status', 'created_at']);
            $table->index(['organization_id', 'severity', 'created_at']);
            $table->index(['organization_id', 'created_at']);
            $table->index(['service_id', 'status']);
            $table->index('commander_id');
        });

        Schema::create('incident_assignees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 32)->default('responder');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('assigned_at');
            $table->timestampsTz();

            // This table holds the *current* roster only. Assignment history is
            // the append-only incident_events stream, so a simple unique pair
            // is enough and re-assigning someone later is never blocked.
            $table->unique(['incident_id', 'user_id']);
            $table->index(['user_id', 'incident_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_assignees');
        Schema::dropIfExists('incidents');
    }
};
