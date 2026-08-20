<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recording_chunks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recording_session_id')->constrained()->cascadeOnDelete();
            $table->string('epoch_id', 128);
            $table->unsignedInteger('sequence');
            $table->char('checksum', 64);
            $table->unsignedInteger('compressed_bytes');
            $table->unsignedInteger('decompressed_bytes');
            $table->unsignedBigInteger('event_started_at');
            $table->unsignedBigInteger('event_ended_at');
            $table->string('object_key');
            $table->timestamps();

            $table->unique(
                ['application_id', 'recording_session_id', 'epoch_id', 'sequence'],
                'recording_chunks_identity_unique',
            );
            $table->index(['recording_session_id', 'epoch_id', 'sequence']);
        });

        Schema::create('recording_session_transitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recording_session_id')->constrained()->cascadeOnDelete();
            $table->string('previous_state')->nullable();
            $table->string('new_state');
            $table->string('reason');
            $table->unsignedInteger('attempt')->default(1);
            $table->timestamp('transitioned_at');

            $table->index(['recording_session_id', 'transitioned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recording_session_transitions');
        Schema::dropIfExists('recording_chunks');
    }
};
