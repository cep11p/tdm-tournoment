<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_tie_game_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_tie_game_id')->constrained('team_tie_games')->cascadeOnDelete();
            $table->foreignId('competition_entry_member_id')->constrained('competition_entry_members')->restrictOnDelete();
            $table->string('side');
            $table->unsignedTinyInteger('player_order');
            $table->timestamps();

            $table->unique(['team_tie_game_id', 'competition_entry_member_id'], 'team_tie_game_members_game_member_unique');
            $table->unique(['team_tie_game_id', 'side', 'player_order'], 'team_tie_game_members_game_side_order_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_tie_game_members');
    }
};
