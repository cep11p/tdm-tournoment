<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_manual_tiebreaks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('reason');
            $table->text('notes')->nullable();
            $table->timestamp('applied_at');
            $table->timestamps();
        });

        Schema::create('group_manual_tiebreak_players', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_manual_tiebreak_id')
                ->constrained('group_manual_tiebreaks')
                ->cascadeOnDelete();
            $table->foreignId('player_id')
                ->constrained()
                ->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->unique(['group_manual_tiebreak_id', 'player_id'], 'gmtbp_tiebreak_player_unique');
            $table->unique(['group_manual_tiebreak_id', 'position'], 'gmtbp_tiebreak_position_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_manual_tiebreak_players');
        Schema::dropIfExists('group_manual_tiebreaks');
    }
};
