<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ranking_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ranking_id')
                ->constrained('rankings')
                ->cascadeOnDelete();
            $table->foreignId('competition_id')
                ->constrained('competitions')
                ->cascadeOnDelete();
            $table->foreignId('player_id')
                ->constrained('players')
                ->restrictOnDelete();
            $table->foreignId('ranking_rule_id')
                ->nullable()
                ->constrained('ranking_rules')
                ->nullOnDelete();
            $table->string('competition_type');
            $table->foreignId('category_id')
                ->nullable()
                ->constrained('categories')
                ->nullOnDelete();
            $table->unsignedSmallInteger('position');
            $table->unsignedSmallInteger('position_range_end');
            $table->string('source');
            $table->unsignedInteger('points');
            $table->string('player_display_name_snapshot');
            $table->string('competition_name_snapshot');
            $table->string('ranking_rule_name_snapshot')->nullable();
            $table->timestamps();

            $table->unique(['ranking_id', 'competition_id', 'player_id'], 'rt_ranking_competition_player_unique');
            $table->index(['ranking_id', 'player_id'], 'rt_ranking_player_index');
            $table->index(['competition_id'], 'rt_competition_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ranking_transactions');
    }
};
