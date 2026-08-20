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
            $table->index('name', 'applications_name_idx');
        });

        Schema::table('recording_sessions', function (Blueprint $table): void {
            $table->string('initial_path')->nullable();
            $table->string('latest_path')->nullable();
            $table->unsignedBigInteger('initial_path_recorded_at')->nullable();
            $table->unsignedBigInteger('latest_path_recorded_at')->nullable();
            $table->string('application_user_id')->nullable();
            $table->string('release_id')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamp('protected_at')->nullable();
            $table->foreignId('protected_by')->nullable()->constrained('users')->nullOnDelete();

            $table->index('application_id', 'recording_sessions_application_id_idx');
            $table->index('started_at', 'recording_sessions_started_at_idx');
            $table->index('ended_at', 'recording_sessions_ended_at_idx');
            $table->index('duration_seconds', 'recording_sessions_duration_seconds_idx');
            $table->index('initial_path', 'recording_sessions_initial_path_idx');
            $table->index('latest_path', 'recording_sessions_latest_path_idx');
            $table->index('session_id', 'recording_sessions_session_id_idx');
            $table->index('application_user_id', 'recording_sessions_application_user_id_idx');
            $table->index('release_id', 'recording_sessions_release_id_idx');
            $table->index('status', 'recording_sessions_status_idx');
            $table->index('protected_at', 'recording_sessions_protected_at_idx');
        });

        DB::statement(<<<'SQL'
UPDATE recording_sessions
SET duration_seconds = GREATEST(0, EXTRACT(EPOCH FROM (ended_at - started_at))::integer)
WHERE ended_at IS NOT NULL
SQL);

        Schema::create('recording_markers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recording_session_id')->constrained()->cascadeOnDelete();
            $table->string('marker_type');
            $table->unsignedBigInteger('occurred_at');
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();

            $table->index('marker_type', 'recording_markers_marker_type_idx');
            $table->index('occurred_at', 'recording_markers_occurred_at_idx');
            $table->index(
                ['recording_session_id', 'marker_type'],
                'recording_markers_session_type_idx',
            );
            $table->index(
                ['application_id', 'marker_type'],
                'recording_markers_application_type_idx',
            );
        });

        Schema::create('replay_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recording_session_id')->constrained()->cascadeOnDelete();
            $table->timestamp('viewed_at');
            $table->timestamps();

            $table->index(
                ['user_id', 'recording_session_id'],
                'replay_views_user_session_idx',
            );
            $table->index(
                ['application_id', 'viewed_at'],
                'replay_views_application_viewed_at_idx',
            );
            $table->index(
                ['recording_session_id', 'viewed_at'],
                'replay_views_session_viewed_at_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('replay_views');
        Schema::dropIfExists('recording_markers');

        Schema::table('applications', function (Blueprint $table): void {
            $table->dropIndex('applications_name_idx');
        });

        Schema::table('recording_sessions', function (Blueprint $table): void {
            $table->dropIndex('recording_sessions_application_id_idx');
            $table->dropIndex('recording_sessions_started_at_idx');
            $table->dropIndex('recording_sessions_ended_at_idx');
            $table->dropIndex('recording_sessions_duration_seconds_idx');
            $table->dropIndex('recording_sessions_initial_path_idx');
            $table->dropIndex('recording_sessions_latest_path_idx');
            $table->dropIndex('recording_sessions_session_id_idx');
            $table->dropIndex('recording_sessions_application_user_id_idx');
            $table->dropIndex('recording_sessions_release_id_idx');
            $table->dropIndex('recording_sessions_status_idx');
            $table->dropIndex('recording_sessions_protected_at_idx');
            $table->dropConstrainedForeignId('protected_by');
            $table->dropColumn([
                'initial_path',
                'latest_path',
                'initial_path_recorded_at',
                'latest_path_recorded_at',
                'application_user_id',
                'release_id',
                'duration_seconds',
                'protected_at',
            ]);
        });
    }
};
