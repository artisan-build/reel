<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recording_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_credential_id')->constrained()->restrictOnDelete();
            $table->char('session_id', 64);
            $table->string('grant_id_hash', 64);
            $table->string('origin');
            $table->enum('status', ['recording', 'closing', 'compacting', 'ready', 'deleting', 'deleted', 'failed']);
            $table->unsignedSmallInteger('protocol_version');
            $table->unsignedInteger('max_chunks');
            $table->unsignedBigInteger('max_compressed_bytes');
            $table->unsignedInteger('max_chunk_bytes');
            $table->unsignedInteger('chunk_count')->default(0);
            $table->unsignedBigInteger('compressed_bytes')->default(0);
            $table->unsignedInteger('conflicting_retry_count')->default(0);
            $table->unsignedInteger('epoch_count')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('max_event_time');
            $table->timestamp('upload_cutoff_at');
            $table->timestamp('closing_at')->nullable();
            $table->string('failure_code')->nullable();
            $table->timestamps();

            $table->unique(['application_id', 'session_id']);
            $table->index(['application_id', 'started_at']);
            $table->index(['application_id', 'created_at']);
            $table->index(['application_id', 'status', 'upload_cutoff_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recording_sessions');
    }
};
