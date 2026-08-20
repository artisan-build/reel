<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recording_epochs', function (Blueprint $table): void {
            $table->unsignedInteger('ordinal')->nullable()->comment('Server-assigned first-seen order');
        });

        DB::statement(<<<'SQL'
UPDATE recording_epochs
SET ordinal = ranked.ordinal
FROM (
    SELECT id, (ROW_NUMBER() OVER (
        PARTITION BY recording_session_id
        ORDER BY created_at, id
    ))::integer AS ordinal
    FROM recording_epochs
) AS ranked
WHERE recording_epochs.id = ranked.id
SQL);
        DB::statement('ALTER TABLE recording_epochs ALTER COLUMN ordinal SET NOT NULL');

        Schema::table('recording_epochs', function (Blueprint $table): void {
            $table->unique(['recording_session_id', 'ordinal']);
        });

        Schema::table('recording_sessions', function (Blueprint $table): void {
            $table->unsignedBigInteger('compaction_peak_memory_bytes')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('recording_sessions', function (Blueprint $table): void {
            $table->dropColumn('compaction_peak_memory_bytes');
        });

        Schema::table('recording_epochs', function (Blueprint $table): void {
            $table->dropUnique(['recording_session_id', 'ordinal']);
            $table->dropColumn('ordinal');
        });
    }
};
