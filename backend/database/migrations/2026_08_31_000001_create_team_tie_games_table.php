<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_tie_games', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_tie_id')->constrained('team_ties')->cascadeOnDelete();
            $table->foreignId('game_id')->unique()->constrained('games')->restrictOnDelete();
            $table->unsignedTinyInteger('slot_order');
            $table->string('modality');
            $table->timestamps();

            $table->unique(['team_tie_id', 'slot_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_tie_games');
    }
};
