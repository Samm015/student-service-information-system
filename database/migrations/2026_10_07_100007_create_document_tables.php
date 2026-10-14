<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();            // TOR, COE ...
            $table->string('name');
            $table->decimal('fee', 10, 2)->default(0);       // fee PER COPY
            $table->unsignedSmallInteger('processing_days')->default(3);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('document_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference_no', 20)->nullable()->unique(); // DR-0045, set right after insert
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_type_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('copies')->default(1);
            $table->string('purpose');
            $table->date('pickup_date')->nullable();
            // pending | awaiting_payment | processing | ready | released | rejected | cancelled
            $table->string('status', 20)->default('pending');
            $table->decimal('fee_total', 10, 2)->default(0); // snapshot: fee x copies at request time
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_requests');
        Schema::dropIfExists('document_types');
    }
};
