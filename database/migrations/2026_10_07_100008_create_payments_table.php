<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            // Only set when the payment is for a document request.
            $table->foreignId('document_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('fee_type', 30);                  // tuition | miscellaneous | document_fee | clearance_fee
            $table->decimal('amount', 10, 2);
            $table->string('status', 20)->default('pending'); // pending | paid | void
            $table->string('or_number', 20)->nullable()->unique(); // official receipt no., set when paid
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete(); // cashier
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'status']);
            $table->index('status');
            $table->index('paid_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
