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
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('role_code')->unique();
            $table->string('role_name');
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->json('allowed_groups')->nullable();
            $table->json('allowed_views')->nullable();
            $table->json('allowed_sources')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
