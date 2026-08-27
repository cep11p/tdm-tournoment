<?php

use App\Enums\BracketGamePurpose;
use App\Enums\GameStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            $this->rebuildGamesTableForSqlite();

            return;
        }

        Schema::table('games', function (Blueprint $table): void {
            $table->dropForeign(['player1_id']);
            $table->dropForeign(['player2_id']);
            $table->dropForeign(['winner_id']);
        });

        Schema::table('games', function (Blueprint $table): void {
            $table->dropColumn(['player1_id', 'player2_id', 'winner_id']);
        });

        Schema::table('games', function (Blueprint $table): void {
            $table->unsignedBigInteger('entry1_id');
            $table->unsignedBigInteger('entry2_id')->nullable();
            $table->unsignedBigInteger('winner_entry_id')->nullable();

            $this->addEntryForeignKeys($table);
        });

        $this->addEntryCheckConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('games', function (Blueprint $table): void {
            $table->dropForeign('games_entry1_competition_fk');
            $table->dropForeign('games_entry2_competition_fk');
            $table->dropForeign('games_winner_entry_competition_fk');
        });

        Schema::table('games', function (Blueprint $table): void {
            $table->dropColumn(['entry1_id', 'entry2_id', 'winner_entry_id']);
        });

        Schema::table('games', function (Blueprint $table): void {
            $table->foreignId('player1_id')
                ->constrained('players')
                ->restrictOnDelete();
            $table->unsignedBigInteger('player2_id')->nullable();
            $table->foreignId('winner_id')
                ->nullable()
                ->constrained('players')
                ->restrictOnDelete();

            $table->foreign('player2_id')
                ->references('id')
                ->on('players')
                ->restrictOnDelete();
        });
    }

    private function rebuildGamesTableForSqlite(): void
    {
        DB::statement('PRAGMA foreign_keys=OFF');

        Schema::dropIfExists('games');

        Schema::create('games', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('competition_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('group_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('bracket_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();
            $table->unsignedBigInteger('entry1_id');
            $table->unsignedBigInteger('entry2_id')->nullable();
            $table->unsignedBigInteger('winner_entry_id')->nullable();
            $table->string('status')->default(GameStatus::Pending->value)->index();
            $table->boolean('is_bye')->default(false);
            $table->unsignedSmallInteger('best_of')->nullable();
            $table->unsignedSmallInteger('sets_to_win')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('round')->nullable()->index();
            $table->unsignedSmallInteger('table_number')->nullable();
            $table->unsignedSmallInteger('bracket_round')->nullable();
            $table->unsignedSmallInteger('bracket_match')->nullable();
            $table->string('bracket_purpose')->default(BracketGamePurpose::Main->value);
            $table->unsignedSmallInteger('group_round')->nullable();
            $table->unsignedSmallInteger('group_match')->nullable();
            $table->timestamps();

            $table->index(['competition_id', 'status']);
            $table->index(['group_id', 'group_round', 'group_match']);

            $this->addEntryForeignKeys($table);
        });

        $this->addEntryCheckConstraints();

        DB::statement('PRAGMA foreign_keys=ON');
    }

    private function addEntryForeignKeys(Blueprint $table): void
    {
        $table->foreign(['entry1_id', 'competition_id'], 'games_entry1_competition_fk')
            ->references(['id', 'competition_id'])
            ->on('competition_entries')
            ->restrictOnDelete();

        $table->foreign(['entry2_id', 'competition_id'], 'games_entry2_competition_fk')
            ->references(['id', 'competition_id'])
            ->on('competition_entries')
            ->restrictOnDelete();

        $table->foreign(['winner_entry_id', 'competition_id'], 'games_winner_entry_competition_fk')
            ->references(['id', 'competition_id'])
            ->on('competition_entries')
            ->restrictOnDelete();
    }

    private function addEntryCheckConstraints(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE games ADD CONSTRAINT games_distinct_entries_check CHECK (entry2_id IS NULL OR entry1_id <> entry2_id)');
        DB::statement('ALTER TABLE games ADD CONSTRAINT games_winner_is_side_check CHECK (winner_entry_id IS NULL OR winner_entry_id = entry1_id OR winner_entry_id = entry2_id)');
    }
};
