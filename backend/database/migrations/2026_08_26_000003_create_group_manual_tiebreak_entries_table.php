<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_manual_tiebreak_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_manual_tiebreak_id')
                ->constrained('group_manual_tiebreaks')
                ->cascadeOnDelete();
            $table->foreignId('competition_entry_id')
                ->constrained('competition_entries')
                ->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->unique(['group_manual_tiebreak_id', 'competition_entry_id'], 'gmtbe_tiebreak_entry_unique');
            $table->unique(['group_manual_tiebreak_id', 'position'], 'gmtbe_tiebreak_position_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_manual_tiebreak_entries');
    }
};
