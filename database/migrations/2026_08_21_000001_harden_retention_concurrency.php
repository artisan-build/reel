<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recording_sessions', function (Blueprint $table): void {
            $table->uuid('erasure_batch_id')->nullable();
            $table->timestamp('retention_skipped_at')->nullable();
            $table->string('retention_skip_reason')->nullable();

            $table->index(['erasure_batch_id', 'status'], 'recording_sessions_erasure_batch_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('recording_sessions', function (Blueprint $table): void {
            $table->dropIndex('recording_sessions_erasure_batch_status_idx');
            $table->dropColumn(['erasure_batch_id', 'retention_skipped_at', 'retention_skip_reason']);
        });
    }
};
