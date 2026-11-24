<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Requests without coordinates used to log distance_km = 9999 (or 0). Both are wrong
 * values for an ML feature; "unknown" is NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donor_response_logs', function (Blueprint $table) {
            $table->decimal('distance_km', 6, 2)->nullable()->change();
        });

        DB::table('donor_response_logs')->where('distance_km', '>=', 9999)->update(['distance_km' => null]);
    }

    public function down(): void
    {
        DB::table('donor_response_logs')->whereNull('distance_km')->update(['distance_km' => 9999]);

        Schema::table('donor_response_logs', function (Blueprint $table) {
            $table->decimal('distance_km', 6, 2)->nullable(false)->change();
        });
    }
};
