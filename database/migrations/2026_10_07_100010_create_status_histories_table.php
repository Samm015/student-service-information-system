<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One shared history table for every request-like record (enrollment,
     * document request, payment, clearance item). `trackable_*` points to the row.
     */
    public function up(): void
    {
        Schema::create('status_histories', function (Blueprint $table) {
            $table->id();
            $table->morphs('trackable');                     // trackable_type + trackable_id (indexed)
            $table->string('from_status', 20)->nullable();   // null on first status
            $table->string('to_status', 20);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamp('created_at')->useCurrent();   // history rows are never edited
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_histories');
    }
};
