<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Departments" holds BOTH academic departments (CCS, CAS) and service
     * offices (Library, Cashier, Registrar, Guidance). The `type` column tells
     * them apart. Clearance sign-offs are owned by either kind.
     */
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();        // CCS, LIB, CSH ...
            $table->string('name');
            $table->string('type', 20)->default('academic'); // academic | office
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Staff accounts belong to the department/office they work for.
        // Nullable: students and admins have none.
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('department_id')
                ->nullable()
                ->constrained('departments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
        });
        Schema::dropIfExists('departments');
    }
};
