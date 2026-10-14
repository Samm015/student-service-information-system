<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The checklist template: what every student must clear each term.
        Schema::create('clearance_requirements', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            // Responsible office. NULL means "the student's own academic department".
            $table->foreignId('department_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // A student's clearance request for one term.
        Schema::create('clearances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->string('school_year', 9);
            $table->string('semester', 20);
            $table->string('status', 20)->default('pending'); // pending | cleared
            $table->timestamp('cleared_at')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'school_year', 'semester'], 'clearances_student_term_unique');
        });

        // One row per requirement per clearance = the individual sign-offs.
        Schema::create('clearance_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clearance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clearance_requirement_id')->constrained()->restrictOnDelete();
            // Snapshot of the responsible office (resolved when the clearance is created).
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('pending'); // pending | signed | rejected
            $table->foreignId('signed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('signed_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->unique(['clearance_id', 'clearance_requirement_id'], 'clearance_items_unique');
            $table->index(['department_id', 'status']);      // office sign-off queues
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clearance_items');
        Schema::dropIfExists('clearances');
        Schema::dropIfExists('clearance_requirements');
    }
};
