<?php

use App\Enums\CompetitionEntryStatus;
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
        Schema::create('competition_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('competition_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('status')->default(CompetitionEntryStatus::Active->value);
            $table->timestamps();

            $table->unique(['id', 'competition_id'], 'ce_id_competition_unique');
        });

        Schema::create('competition_entry_members', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('competition_entry_id');
            $table->foreignId('competition_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('player_id')
                ->constrained()
                ->restrictOnDelete();
            $table->unsignedTinyInteger('member_order')->default(1);
            $table->timestamps();

            $table->unique(['competition_entry_id', 'player_id'], 'cem_entry_player_unique');
            $table->unique(['competition_entry_id', 'member_order'], 'cem_entry_order_unique');
            $table->unique(['competition_id', 'player_id'], 'cem_competition_player_unique');

            $table->foreign(['competition_entry_id', 'competition_id'], 'cem_entry_competition_fk')
                ->references(['id', 'competition_id'])
                ->on('competition_entries')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('competition_entry_members');
        Schema::dropIfExists('competition_entries');
    }
};
