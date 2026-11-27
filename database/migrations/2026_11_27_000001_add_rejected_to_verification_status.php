<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The admin "reject" button writes 'rejected', which the original enum did not allow. */
return new class extends Migration
{
    public function up(): void
    {
        $this->setAllowed(['pending', 'claimed', 'verified', 'disputed', 'rejected']);
    }

    public function down(): void
    {
        DB::table('blood_request_responses')->where('verification_status', 'rejected')->update(['verification_status' => 'disputed']);
        $this->setAllowed(['pending', 'claimed', 'verified', 'disputed']);
    }

    private function setAllowed(array $values): void
    {
        Schema::table('blood_request_responses', function (Blueprint $table) use ($values) {
            $table->enum('verification_status', $values)->default('pending')->change();
        });
    }
};
