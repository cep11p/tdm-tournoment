<?php

use App\Enums\MatchStatus;
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
        Schema::create('matches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('competition_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('player1_id')
                ->constrained('players')
                ->restrictOnDelete();
            $table->foreignId('player2_id')
                ->constrained('players')
                ->restrictOnDelete();
            $table->foreignId('winner_id')
                ->nullable()
                ->constrained('players')
                ->restrictOnDelete();
            $table->string('status')->default(MatchStatus::Pending->value)->index();
            $table->string('round')->nullable();
            $table->timestamps();

            $table->index(['competition_id', 'status']);
            $table->index('round');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('matches');
    }
};
