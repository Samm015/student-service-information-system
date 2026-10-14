<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The student no longer chooses a pickup date. Registration decides when the document will be
 * released, so the column is renamed to say what it now means. Existing values are kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('document_requests', 'pickup_date')) {
            Schema::table('document_requests', function (Blueprint $table) {
                $table->renameColumn('pickup_date', 'expected_release_date');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('document_requests', 'expected_release_date')) {
            Schema::table('document_requests', function (Blueprint $table) {
                $table->renameColumn('expected_release_date', 'pickup_date');
            });
        }
    }
};
