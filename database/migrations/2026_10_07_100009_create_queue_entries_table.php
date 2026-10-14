<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->string('service', 20);                   // enrollment | clearance | grades | documents | payment
            $table->date('queue_date');
            $table->unsignedInteger('queue_number');         // 1, 2, 3 ... restarts every day
            $table->string('queue_code', 10);                // A-024 (what the student sees)
            // waiting | serving | completed | skipped | cancelled
            $table->string('status', 20)->default('waiting');
            $table->string('counter', 30)->nullable();       // "Window 1"
            $table->foreignId('served_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();     // processing start
            $table->timestamp('completed_at')->nullable();

            // Duplicate-prevention trick: this is `true` while the entry is
            // waiting/serving and NULL once it is finished. The UNIQUE index
            // below ignores NULLs, so a student can have many finished entries
            // but only ONE active entry per service. The model keeps it in sync.
            $table->boolean('is_active')->nullable();
            $table->timestamps();

            $table->unique(['queue_date', 'queue_number']);
            $table->unique(['student_id', 'service', 'is_active'], 'queue_one_active_per_service');
            $table->index(['queue_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_entries');
    }
};
