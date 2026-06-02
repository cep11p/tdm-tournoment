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
        Schema::create('registrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('competition_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('player_id')
                ->constrained()
                ->restrictOnDelete();
            $table->timestamps();

            $table->unique(['competition_id', 'player_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registrations');
    }
};
