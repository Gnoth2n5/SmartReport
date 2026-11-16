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
        Schema::create('custom_views', function (Blueprint $table) {
            $table->id();
            $table->string('view_code')->unique();
            $table->string('view_name');
            $table->string('menu_group_code')->nullable();
            $table->string('execution_mode'); // single_or_union | federated_join
            $table->json('allowed_sources')->nullable();
            $table->json('default_sources')->nullable();
            $table->json('source_queries')->nullable();
            $table->longText('sql_text')->nullable();
            $table->json('filters')->nullable();
            $table->json('roles')->nullable();
            $table->text('description')->nullable();
            $table->string('version')->default('1.0.0');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('custom_views');
    }
};
