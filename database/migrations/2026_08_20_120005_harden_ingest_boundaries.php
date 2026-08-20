<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->unsignedInteger('max_daily_chunks')->default(100_000);
            $table->unsignedBigInteger('max_daily_compressed_bytes')->default(10 * 1024 * 1024 * 1024);
            $table->unsignedInteger('max_ingest_requests_per_minute')->default(600);
        });

        Schema::create('recording_epochs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recording_session_id')->constrained()->cascadeOnDelete();
            $table->string('epoch_id', 128);
            $table->enum('status', ['active', 'failed']);
            $table->string('failure_code')->nullable();
            $table->timestamps();

            $table->unique(['recording_session_id', 'epoch_id']);
        });

        Schema::create('recording_epoch_transitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recording_epoch_id')->constrained('recording_epochs')->cascadeOnDelete();
            $table->string('previous_state')->nullable();
            $table->string('new_state');
            $table->string('reason');
            $table->unsignedInteger('attempt')->default(1);
            $table->timestamp('transitioned_at');
        });

        Schema::create('ingest_rate_counters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->timestamp('window_started_at');
            $table->unsignedInteger('request_count');

            $table->unique('application_id');
        });

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

CREATE TRIGGER recording_sessions_transition_guard
BEFORE UPDATE OF status ON recording_sessions
FOR EACH ROW EXECUTE FUNCTION enforce_recording_session_transition();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS recording_sessions_transition_guard ON recording_sessions');
        DB::unprepared('DROP FUNCTION IF EXISTS enforce_recording_session_transition()');
        Schema::dropIfExists('ingest_rate_counters');
        Schema::dropIfExists('recording_epoch_transitions');
        Schema::dropIfExists('recording_epochs');

        Schema::table('applications', function (Blueprint $table): void {
            $table->dropColumn([
                'max_daily_chunks',
                'max_daily_compressed_bytes',
                'max_ingest_requests_per_minute',
            ]);
        });
    }
};
