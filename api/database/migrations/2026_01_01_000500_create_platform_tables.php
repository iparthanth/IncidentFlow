<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('incident_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('channel', 16);
            $table->string('type', 64);
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();

            // The unread badge query: "my notifications, unread, newest first".
            $table->index(['user_id', 'read_at', 'created_at']);
            $table->index(['status', 'channel']);
            $table->index(['organization_id', 'created_at']);
        });

        /**
         * Append-only audit trail.
         *
         * Actor identity is snapshotted alongside the foreign key: a deleted
         * user must not turn an audit row into "someone did this".
         */
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_email')->nullable();
            $table->string('action', 64);
            $table->string('auditable_type', 64)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            /** {"before": {...}, "after": {...}} with sensitive keys stripped. */
            $table->json('changes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index(['organization_id', 'created_at']);
            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['actor_id', 'created_at']);
            $table->index('action');
        });

        /**
         * Idempotency records for unsafe POSTs.
         *
         * `request_hash` is what makes this safe rather than merely convenient:
         * replaying a key with a *different* body is a client bug and is
         * rejected with 422, instead of silently returning the wrong resource.
         */
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 255);
            $table->string('endpoint', 160);
            $table->string('request_hash', 64);
            $table->string('status', 16)->default('in_progress');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->string('resource_type', 64)->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampsTz();

            // The uniqueness that does the actual work: one key per caller per
            // endpoint. Concurrency is resolved by letting the second writer
            // lose this insert, not by an application-level check.
            $table->unique(['user_id', 'endpoint', 'key']);
            $table->index('expires_at');
        });

        /**
         * Rotating refresh tokens.
         *
         * Only the SHA-256 hash is stored — a database dump must not yield
         * usable credentials. `family_id` links every token descended from one
         * login so that replaying a already-rotated token can revoke the whole
         * family (the standard detection for a stolen refresh token).
         */
        Schema::create('refresh_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->uuid('family_id');
            $table->foreignId('parent_id')->nullable()->constrained('refresh_tokens')->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revoked_reason', 64)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'revoked_at']);
            $table->index('family_id');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_tokens');
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('notifications');
    }
};
