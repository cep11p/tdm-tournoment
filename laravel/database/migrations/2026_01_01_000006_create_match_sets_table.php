<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->unsignedTinyInteger('set_number');
            $table->unsignedSmallInteger('player1_score');
            $table->unsignedSmallInteger('player2_score');
            $table->timestamps();

            $table->unique(['match_id', 'set_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_sets');
    }
};
