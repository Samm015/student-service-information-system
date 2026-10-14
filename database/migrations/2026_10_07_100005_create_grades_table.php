<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->string('school_year', 9);
            $table->string('semester', 20);
            $table->decimal('grade', 3, 2)->nullable();      // 1.00 - 5.00, null = not yet graded
            $table->string('remarks', 20)->nullable();       // passed | failed | incomplete | dropped
            $table->foreignId('encoded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('encoded_at')->nullable();
            $table->timestamps();

            // One grade per student per subject per term.
            $table->unique(['student_id', 'subject_id', 'school_year', 'semester'], 'grades_student_subject_term_unique');
            $table->index(['school_year', 'semester']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grades');
    }
};
