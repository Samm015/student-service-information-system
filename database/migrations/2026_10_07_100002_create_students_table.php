<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            // One login account per student. restrictOnDelete: a user cannot be
            // deleted while a student record (and its history) depends on it.
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('student_no', 20)->unique();      // e.g. 2024-00001
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->string('program', 20);                   // BSIT, BSCS ...
            $table->unsignedTinyInteger('year_level')->default(1);
            $table->string('status', 20)->default('active'); // active | inactive | graduated | leave
            $table->timestamps();
            $table->softDeletes();                           // keep history if a student is removed

            $table->index(['department_id', 'program']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
