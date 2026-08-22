<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('timezone', 64)->default('UTC');
            /**
             * Per-tenant incident counter. Allocated under a row lock inside the
             * creation transaction, which makes INC-0001, INC-0002 … race-free
             * in O(1) — no COUNT(*) that degrades as the table grows, and no
             * chance of two concurrent reporters being handed the same number.
             */
            $table->unsignedBigInteger('incident_sequence')->default(0);
            // Per-tenant knobs (business hours, severity SLA targets) that must
            // not require a migration to change.
            $table->json('settings')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('organization_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Role is a string, not a database enum: adding a role must be a
            // code deploy, never an ALTER TYPE that locks the table.
            $table->string('role', 32);
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('joined_at')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'user_id']);
            $table->index(['user_id']);
            $table->index(['organization_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_members');
        Schema::dropIfExists('organizations');
    }
};
