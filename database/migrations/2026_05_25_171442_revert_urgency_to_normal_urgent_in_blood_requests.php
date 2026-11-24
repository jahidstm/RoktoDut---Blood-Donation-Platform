<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // `MODIFY ... ENUM` is MySQL-only. On other drivers (the SQLite test database)
        // the column is already a plain string, so only the data rewrite is needed.
        $isMysql = Schema::getConnection()->getDriverName() === 'mysql';

        // Change to VARCHAR first so we can update the strings without truncation
        if ($isMysql) {
            DB::statement('ALTER TABLE `blood_requests` MODIFY `urgency` VARCHAR(255) NOT NULL');
        }
        
        // Update data
        DB::table('blood_requests')->where('urgency', 'medium')->update(['urgency' => 'normal']);
        DB::table('blood_requests')->where('urgency', 'high')->update(['urgency' => 'urgent']);
        
        // Change to new ENUM
        if ($isMysql) {
            DB::statement("ALTER TABLE `blood_requests` MODIFY `urgency` ENUM('normal', 'urgent', 'emergency') NOT NULL DEFAULT 'normal'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $isMysql = Schema::getConnection()->getDriverName() === 'mysql';

        if ($isMysql) {
            DB::statement('ALTER TABLE `blood_requests` MODIFY `urgency` VARCHAR(255) NOT NULL');
        }
        
        DB::table('blood_requests')->where('urgency', 'normal')->update(['urgency' => 'medium']);
        DB::table('blood_requests')->where('urgency', 'urgent')->update(['urgency' => 'high']);
        
        if ($isMysql) {
            DB::statement("ALTER TABLE `blood_requests` MODIFY `urgency` ENUM('medium', 'high', 'emergency') NOT NULL DEFAULT 'medium'");
        }
    }
};
