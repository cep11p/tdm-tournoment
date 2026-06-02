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
        Schema::create('players', function (Blueprint $table): void {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('nickname')->nullable();
            $table->timestamps();

            $table->index(['last_name', 'first_name']);
            $table->index('nickname');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('players');
    }
};
