<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_provisioning_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->foreignId('application_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('enrollment_code_ciphertext')->nullable();
            $table->timestamp('enrollment_expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_provisioning_requests');
    }
};
