<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ranking_standings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ranking_id')
                ->constrained('rankings')
                ->cascadeOnDelete();
            $table->foreignId('player_id')
                ->constrained('players')
                ->restrictOnDelete();
            $table->unsignedInteger('points_total')->default(0);
            $table->unsignedInteger('events_count')->default(0);
            $table->timestamps();

            $table->unique(['ranking_id', 'player_id'], 'rs_ranking_player_unique');
            $table->index(['ranking_id', 'points_total'], 'rs_ranking_points_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ranking_standings');
    }
};
