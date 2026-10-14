<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An enrollment is BOTH the request and the record: it starts as
     * `pending`, and once Registration approves it, it simply stays as the
     * student's enrollment for that term. No second table needed.
     */
    public function up(): void
    {
        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->string('school_year', 9);                // 2026-2027
            $table->string('semester', 20);                  // 1st Semester | 2nd Semester | Summer
            $table->unsignedTinyInteger('year_level');
            $table->string('status', 20)->default('pending'); // pending | approved | rejected | cancelled
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            // A student can only have one enrollment per term.
            $table->unique(['student_id', 'school_year', 'semester'], 'enrollments_student_term_unique');
            $table->index('status');
        });

        // Subjects picked in an enrollment (many-to-many).
        Schema::create('enrollment_subject', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete(); // lines die with their enrollment
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['enrollment_id', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_subject');
        Schema::dropIfExists('enrollments');
    }
};
