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
        if (Schema::hasTable('game_sets') || Schema::hasTable('match_sets')) {
            return;
        }

        Schema::create('game_sets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_id')
                ->constrained('games')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('set_number');
            $table->unsignedSmallInteger('player1_score');
            $table->unsignedSmallInteger('player2_score');
            $table->timestamps();

            $table->unique(['game_id', 'set_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('game_sets');
    }
};
