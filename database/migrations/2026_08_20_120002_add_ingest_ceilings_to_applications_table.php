<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->unsignedInteger('max_new_sessions_per_day')->default(1_000);
            $table->unsignedInteger('max_concurrent_sessions')->default(100);
            $table->unsignedInteger('max_chunks_per_session')->default(360);
            $table->unsignedBigInteger('max_compressed_bytes_per_session')->default(64 * 1024 * 1024);
            $table->unsignedInteger('max_compressed_chunk_bytes')->default(256 * 1024);
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->dropColumn([
                'max_new_sessions_per_day',
                'max_concurrent_sessions',
                'max_chunks_per_session',
                'max_compressed_bytes_per_session',
                'max_compressed_chunk_bytes',
            ]);
        });
    }
};
