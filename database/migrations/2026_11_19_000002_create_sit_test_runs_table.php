<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sit_test_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sit_template_id')->constrained('sit_templates')->cascadeOnDelete();
            $table->string('run_code')->unique();
            $table->string('run_title');
            $table->string('environment')->default('SIT');
            $table->string('tester_name')->nullable();
            $table->timestamp('tested_at')->nullable();
            $table->string('status')->default('IN_PROGRESS');
            $table->text('summary_notes')->nullable();
            $table->json('steps_data')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sit_test_runs');
    }
};
