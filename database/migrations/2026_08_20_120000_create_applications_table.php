<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('name');
            $table->json('allowed_origins');
            $table->enum('severity', ['inputs', 'all_text'])->default('inputs');
            $table->json('mask_selectors')->default('[]');
            $table->json('block_selectors')->default('[]');
            $table->json('excluded_paths')->default('[]');
            $table->unsignedSmallInteger('sampling_percent')->default(100);
            $table->boolean('ingest_enabled')->default(true);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE applications ADD CONSTRAINT applications_sampling_percent_check CHECK (sampling_percent BETWEEN 0 AND 100)');
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
