<?php

use App\Enums\TeamTieStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_ties', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('competition_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('group_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();
            $table->unsignedBigInteger('entry1_id');
            $table->unsignedBigInteger('entry2_id')->nullable();
            $table->unsignedBigInteger('winner_entry_id')->nullable();
            $table->foreignId('team_tie_format_id')
                ->constrained('team_tie_formats')
                ->restrictOnDelete();
            $table->unsignedTinyInteger('victories_required');
            $table->string('format_name');
            $table->string('status')->default(TeamTieStatus::Pending->value)->index();
            $table->boolean('is_bye')->default(false);
            $table->unsignedSmallInteger('group_round')->nullable();
            $table->unsignedSmallInteger('group_match')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['competition_id', 'status']);
            $table->unique(['group_id', 'group_round', 'group_match']);

            $this->addEntryForeignKeys($table);
        });

        $this->addCheckConstraints();
    }

    public function down(): void
    {
        Schema::dropIfExists('team_ties');
    }

    private function addEntryForeignKeys(Blueprint $table): void
    {
        $table->foreign(['entry1_id', 'competition_id'], 'team_ties_entry1_competition_fk')
            ->references(['id', 'competition_id'])
            ->on('competition_entries')
            ->restrictOnDelete();

        $table->foreign(['entry2_id', 'competition_id'], 'team_ties_entry2_competition_fk')
            ->references(['id', 'competition_id'])
            ->on('competition_entries')
            ->restrictOnDelete();

        $table->foreign(['winner_entry_id', 'competition_id'], 'team_ties_winner_entry_competition_fk')
            ->references(['id', 'competition_id'])
            ->on('competition_entries')
            ->restrictOnDelete();
    }

    private function addCheckConstraints(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE team_ties ADD CONSTRAINT team_ties_distinct_entries_check CHECK (entry2_id IS NULL OR entry1_id <> entry2_id)');
        DB::statement('ALTER TABLE team_ties ADD CONSTRAINT team_ties_winner_is_side_check CHECK (winner_entry_id IS NULL OR winner_entry_id = entry1_id OR winner_entry_id = entry2_id)');
    }
};
