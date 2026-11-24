<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a fifth outcome, "moot": the donor was notified, but the request was settled
 * by someone else before they answered. That is neither a yes, a no, nor ignoring,
 * so it must not count against the donor. The column was an ENUM of four values;
 * a plain string lets the application own the vocabulary (see DonorResponseLogger).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donor_response_logs', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->change();
        });
    }

    public function down(): void
    {
        // moot has no equivalent in the old enum; "ignored" is the closest and keeps the rows valid.
        DB::table('donor_response_logs')->where('status', 'moot')->update(['status' => 'ignored']);

        Schema::table('donor_response_logs', function (Blueprint $table) {
            $table->enum('status', ['pending', 'accepted', 'ignored', 'declined'])->default('pending')->change();
        });
    }
};
