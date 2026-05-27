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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128);
            $table->string('email', 255)->unique();
            $table->string('password', 255);
            
            // Dual Leaky Bucket rate-limiting state
            $table->double('auth_token_count')->default(5.0);
            $table->timestampTz('auth_last_request_at')->useCurrent();
            
            $table->double('api_token_count')->default(60.0);
            $table->timestampTz('api_last_request_at')->useCurrent();
            
            $table->rememberToken();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
