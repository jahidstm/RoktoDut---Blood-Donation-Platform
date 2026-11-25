<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks requests produced by `php artisan demo:generate-demand`, so synthetic
 * demand can never be mistaken for (or mixed into) real demand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blood_requests', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('blood_requests', function (Blueprint $table) {
            $table->dropColumn('is_demo');
        });
    }
};
