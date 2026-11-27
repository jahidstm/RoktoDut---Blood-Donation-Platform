<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The expiry command writes 'expired', which the column did not allow, so on MySQL no request
 * could ever expire. 'cancelled' is new; 'in_progress' is already queried by the code.
 */
return new class extends Migration
{
    private const BEFORE = ['nlp_pending', 'pending', 'approved', 'fulfilled', 'rejected'];

    public function up(): void
    {
        $this->setAllowed([...self::BEFORE, 'in_progress', 'expired', 'cancelled']);
    }

    public function down(): void
    {
        DB::table('blood_requests')->whereIn('status', ['expired', 'cancelled'])->update(['status' => 'rejected']);
        DB::table('blood_requests')->where('status', 'in_progress')->update(['status' => 'pending']);
        $this->setAllowed(self::BEFORE);
    }

    private function setAllowed(array $values): void
    {
        Schema::table('blood_requests', function (Blueprint $table) use ($values) {
            $table->enum('status', $values)->default('pending')->change();
        });
    }
};
