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
        Schema::create('sit_templates', function (Blueprint $table) {
            $table->id();
            $table->string('template_code')->unique();
            $table->string('template_name');
            $table->string('menu_group_code')->nullable();
            $table->string('module_name')->nullable();
            $table->text('description')->nullable();
            $table->string('version')->default('1.0.0');
            $table->json('step_fields')->nullable();
            $table->json('default_steps')->nullable();
            $table->json('roles')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sit_templates');
    }
};
