<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bracket_entry_origins', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bracket_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('competition_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->unsignedBigInteger('competition_entry_id');
            $table->foreignId('group_id')
                ->constrained()
                ->restrictOnDelete();
            $table->string('group_name');
            $table->unsignedTinyInteger('group_position');
            $table->timestamps();

            $table->unique(['bracket_id', 'competition_entry_id'], 'beo_bracket_entry_unique');

            $table->foreign(['competition_entry_id', 'competition_id'], 'beo_entry_competition_fk')
                ->references(['id', 'competition_id'])
                ->on('competition_entries')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bracket_entry_origins');
    }
};
