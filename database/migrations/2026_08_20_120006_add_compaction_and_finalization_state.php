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
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('closing_cutoff_at')->nullable();
            $table->timestamp('maximum_expires_at')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->boolean('is_complete')->nullable();
            $table->jsonb('incomplete_reasons')->default('[]');
            $table->unsignedInteger('gap_count')->default(0);
            $table->unsignedInteger('max_reorder_distance')->default(0);
            $table->unsignedInteger('concurrent_epoch_count')->default(0);
            $table->jsonb('manifest')->nullable();
            $table->char('manifest_checksum', 64)->nullable();
            $table->timestamp('compacted_at')->nullable();
            $table->unsignedInteger('compaction_attempts')->default(0);
            $table->unsignedBigInteger('compaction_duration_ms')->default(0);
            $table->unsignedInteger('compaction_noop_count')->default(0);
            $table->unsignedInteger('candidate_checksum_failure_count')->default(0);
            $table->unsignedInteger('manifest_checksum_failure_count')->default(0);

            $table->index(['status', 'status_changed_at']);
            $table->index('maximum_expires_at');
        });

        DB::table('recording_sessions')->update([
            'maximum_expires_at' => DB::raw("started_at + interval '30 days 31 minutes'"),
            'status_changed_at' => DB::raw('updated_at'),
        ]);
        Schema::table('recording_epochs', function (Blueprint $table): void {
            $table->unsignedInteger('terminal_sequence')->nullable();
        });

        Schema::table('recording_chunks', function (Blueprint $table): void {
            $table->timestamp('purged_at')->nullable();
        });

        Schema::create('operational_counters', function (Blueprint $table): void {
            $table->string('metric')->primary();
            $table->unsignedBigInteger('value')->default(0);
            $table->timestamp('updated_at');
        });

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION enforce_recording_session_transition() RETURNS trigger AS $$
BEGIN
    IF OLD.status IS DISTINCT FROM NEW.status AND NOT (
        (OLD.status = 'recording' AND NEW.status IN ('closing', 'failed', 'deleting')) OR
        (OLD.status = 'closing' AND NEW.status IN ('compacting', 'failed', 'deleting')) OR
        (OLD.status = 'compacting' AND NEW.status IN ('ready', 'failed', 'deleting')) OR
        (OLD.status = 'ready' AND NEW.status = 'deleting') OR
        (OLD.status = 'failed' AND NEW.status = 'deleting') OR
        (OLD.status = 'deleting' AND NEW.status = 'deleted')
    ) THEN
        RAISE EXCEPTION 'illegal recording session transition: % -> %', OLD.status, NEW.status
            USING ERRCODE = 'check_violation';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION enforce_recording_session_transition() RETURNS trigger AS $$
BEGIN
    IF OLD.status IS DISTINCT FROM NEW.status AND NOT (
        (OLD.status = 'recording' AND NEW.status IN ('closing', 'failed')) OR
        (OLD.status = 'closing' AND NEW.status IN ('compacting', 'failed')) OR
        (OLD.status = 'compacting' AND NEW.status IN ('ready', 'failed')) OR
        (OLD.status = 'ready' AND NEW.status = 'deleting') OR
        (OLD.status = 'failed' AND NEW.status = 'deleting') OR
        (OLD.status = 'deleting' AND NEW.status = 'deleted')
    ) THEN
        RAISE EXCEPTION 'illegal recording session transition: % -> %', OLD.status, NEW.status
            USING ERRCODE = 'check_violation';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);

        Schema::dropIfExists('operational_counters');

        Schema::table('recording_chunks', function (Blueprint $table): void {
            $table->dropColumn('purged_at');
        });

        Schema::table('recording_epochs', function (Blueprint $table): void {
            $table->dropColumn('terminal_sequence');
        });

        Schema::table('recording_sessions', function (Blueprint $table): void {
            $table->dropIndex(['status', 'status_changed_at']);
            $table->dropIndex(['maximum_expires_at']);
            $table->dropColumn([
                'ended_at',
                'closing_cutoff_at',
                'maximum_expires_at',
                'status_changed_at',
                'is_complete',
                'incomplete_reasons',
                'gap_count',
                'max_reorder_distance',
                'concurrent_epoch_count',
                'manifest',
                'manifest_checksum',
                'compacted_at',
                'compaction_attempts',
                'compaction_duration_ms',
                'compaction_noop_count',
                'candidate_checksum_failure_count',
                'manifest_checksum_failure_count',
            ]);
        });
    }
};
