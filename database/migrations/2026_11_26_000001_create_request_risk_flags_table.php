<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_risk_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blood_request_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('score');
            // [{code, weight, reason}] exactly as shown to the admin.
            $table->json('signals');
            // open: waiting for a human. dismissed: admin says the request is genuine.
            // confirmed: admin says it is spam. The last two are human labels, kept for later evaluation.
            $table->string('status', 16)->default('open')->index();
            $table->unsignedSmallInteger('reviewed_score')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_risk_flags');
    }
};
