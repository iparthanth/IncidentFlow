<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /** Narrative status updates — the "what we know now" posts. */
        Schema::create('incident_updates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('previous_status', 16)->nullable();
            $table->string('status', 16)->nullable();
            $table->text('message');
            /** Public updates are safe to surface on a customer status page. */
            $table->boolean('is_public')->default(false);
            $table->timestampsTz();

            $table->index(['incident_id', 'created_at']);
        });

        /** Internal discussion thread. */
        Schema::create('incident_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestampTz('edited_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['incident_id', 'created_at']);
        });

        /**
         * The append-only timeline.
         *
         * No updated_at and no deleted_at: there is deliberately nowhere to
         * record a modification, so "the timeline was edited" is not a state
         * this schema can represent. Corrections are new events, never rewrites.
         */
        Schema::create('incident_events', function (Blueprint $table): void {
            $table->id();
            /** Public, sortable identifier; doubles as the SSE event id. */
            $table->ulid('ulid')->unique();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            // Denormalised so org-wide activity feeds and the realtime publisher
            // never need to join back through incidents.
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('type', 64);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            /** Snapshot: the timeline must still read correctly after a user is deleted. */
            $table->string('actor_name')->nullable();
            $table->json('payload')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->nullable();

            $table->index(['incident_id', 'id']);
            $table->index(['organization_id', 'occurred_at']);
            $table->index(['organization_id', 'type']);
        });

        Schema::create('postmortems', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->text('root_cause')->nullable();
            $table->text('contributing_factors')->nullable();
            $table->text('impact')->nullable();
            $table->text('resolution')->nullable();
            $table->text('detection_notes')->nullable();
            $table->text('lessons_learned')->nullable();
            /** [{title, owner_id, due_on, status}] — structured enough to report on. */
            $table->json('action_items')->nullable();
            $table->string('status', 16)->default('draft');
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('postmortems');
        Schema::dropIfExists('incident_events');
        Schema::dropIfExists('incident_comments');
        Schema::dropIfExists('incident_updates');
    }
};
