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
        Schema::create('match_sets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('match_id')
                ->constrained('matches')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('set_number');
            $table->unsignedSmallInteger('player1_score');
            $table->unsignedSmallInteger('player2_score');
            $table->timestamps();

            $table->unique(['match_id', 'set_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('match_sets');
    }
};
