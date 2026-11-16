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
        Schema::create('data_sources', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('driver'); // sqlsrv, pgsql, oracle
            $table->string('host');
            $table->integer('port');
            $table->string('database_name')->nullable();
            $table->string('service_name')->nullable();
            $table->string('default_schema')->nullable();
            $table->string('username');
            $table->text('password'); // encrypted cast
            $table->json('options')->nullable();
            $table->boolean('is_read_only')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('data_sources');
    }
};
