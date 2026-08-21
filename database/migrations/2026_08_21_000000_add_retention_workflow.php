<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recording_sessions', function (Blueprint $table): void {
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('delete_not_before')->nullable();
            $table->timestamp('unprotected_at')->nullable();
            $table->timestamp('deletion_started_at')->nullable();
            $table->timestamp('deletion_completed_at')->nullable();
            $table->unsignedBigInteger('deletion_actor_id')->nullable();
            $table->string('deletion_reason')->nullable();
            $table->unsignedInteger('deletion_attempts')->default(0);
            $table->string('deletion_last_error')->nullable();
            $table->unsignedInteger('deletion_remaining_objects')->default(0);

            $table->index(['protected_at', 'delete_not_before'], 'recording_sessions_retention_due_idx');
            $table->index(['application_id', 'application_user_id'], 'recording_sessions_application_user_idx');
        });

        DB::statement(<<<'SQL'
UPDATE recording_sessions
SET expires_at = COALESCE(ended_at + interval '30 days', maximum_expires_at),
    delete_not_before = COALESCE(ended_at + interval '30 days', maximum_expires_at)
SQL);

        Schema::create('recording_protection_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recording_session_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('actor_name');
            $table->enum('action', ['protected', 'unprotected']);
            $table->timestamp('occurred_at');

            $table->index(['recording_session_id', 'occurred_at'], 'protection_events_session_time_idx');
        });

        Schema::create('user_erasure_audits', function (Blueprint $table): void {
            $table->id();
            $table->uuid('batch_id')->unique();
            $table->unsignedBigInteger('actor_user_id');
            $table->string('actor_name');
            $table->foreignId('application_id')->constrained()->restrictOnDelete();
            $table->timestamp('requested_at');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('deleted_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->enum('outcome', ['running', 'completed', 'partial_failure']);
        });

        Schema::create('retention_states', function (Blueprint $table): void {
            $table->unsignedSmallInteger('id')->primary();
            $table->boolean('orphan_sweeper_suspended')->default(false);
            $table->string('suspension_reason')->nullable();
            $table->timestamp('database_high_water_at')->nullable();
            $table->timestamp('object_high_water_at')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamp('last_retention_sweep_at')->nullable();
            $table->timestamp('last_orphan_sweep_at')->nullable();
            $table->string('last_orphan_sweep_error')->nullable();
            $table->timestamps();
        });

        DB::table('retention_states')->insert([
            'id' => 1,
            'orphan_sweeper_suspended' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('retention_states');
        Schema::dropIfExists('user_erasure_audits');
        Schema::dropIfExists('recording_protection_events');

        Schema::table('recording_sessions', function (Blueprint $table): void {
            $table->dropIndex('recording_sessions_retention_due_idx');
            $table->dropIndex('recording_sessions_application_user_idx');
            $table->dropColumn([
                'expires_at',
                'delete_not_before',
                'unprotected_at',
                'deletion_started_at',
                'deletion_completed_at',
                'deletion_actor_id',
                'deletion_reason',
                'deletion_attempts',
                'deletion_last_error',
                'deletion_remaining_objects',
            ]);
        });
    }
};
