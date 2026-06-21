<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table): void {
            $table->boolean('is_bye')->default(false)->after('status');
        });

        Schema::table('brackets', function (Blueprint $table): void {
            $table->unsignedSmallInteger('bracket_size')->nullable()->after('qualifiers_per_group');
            $table->unsignedSmallInteger('byes_count')->default(0)->after('bracket_size');
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $this->makePlayerTwoNullableForSqlite();

            return;
        }

        Schema::table('games', function (Blueprint $table): void {
            $table->dropForeign(['player2_id']);
        });

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE games MODIFY player2_id BIGINT UNSIGNED NULL');
        } else {
            Schema::table('games', function (Blueprint $table): void {
                $table->unsignedBigInteger('player2_id')->nullable()->change();
            });
        }

        Schema::table('games', function (Blueprint $table): void {
            $table->foreign('player2_id')->references('id')->on('players')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('brackets', function (Blueprint $table): void {
            $table->dropColumn(['bracket_size', 'byes_count']);
        });

        Schema::table('games', function (Blueprint $table): void {
            $table->dropColumn('is_bye');
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        Schema::table('games', function (Blueprint $table): void {
            $table->dropForeign(['player2_id']);
        });

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE games MODIFY player2_id BIGINT UNSIGNED NOT NULL');
        } else {
            Schema::table('games', function (Blueprint $table): void {
                $table->unsignedBigInteger('player2_id')->nullable(false)->change();
            });
        }

        Schema::table('games', function (Blueprint $table): void {
            $table->foreign('player2_id')->references('id')->on('players')->restrictOnDelete();
        });
    }

    private function makePlayerTwoNullableForSqlite(): void
    {
        DB::statement('PRAGMA foreign_keys=OFF');

        DB::statement('CREATE TABLE games__bye_tmp (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            competition_id INTEGER NOT NULL,
            player1_id INTEGER NOT NULL,
            player2_id INTEGER NULL,
            winner_id INTEGER NULL,
            status VARCHAR NOT NULL DEFAULT \'pending\',
            is_bye TINYINT(1) NOT NULL DEFAULT 0,
            finished_at DATETIME NULL,
            round VARCHAR NULL,
            table_number INTEGER NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            group_id INTEGER NULL,
            bracket_id INTEGER NULL,
            bracket_round INTEGER NULL,
            bracket_match INTEGER NULL,
            FOREIGN KEY(competition_id) REFERENCES competitions(id) ON DELETE CASCADE,
            FOREIGN KEY(player1_id) REFERENCES players(id) ON DELETE RESTRICT,
            FOREIGN KEY(player2_id) REFERENCES players(id) ON DELETE RESTRICT,
            FOREIGN KEY(winner_id) REFERENCES players(id) ON DELETE RESTRICT,
            FOREIGN KEY(group_id) REFERENCES groups(id) ON DELETE CASCADE,
            FOREIGN KEY(bracket_id) REFERENCES brackets(id) ON DELETE CASCADE
        )');

        DB::statement('INSERT INTO games__bye_tmp (
            id, competition_id, player1_id, player2_id, winner_id, status, is_bye,
            finished_at, round, table_number, created_at, updated_at,
            group_id, bracket_id, bracket_round, bracket_match
        )
        SELECT
            id, competition_id, player1_id, player2_id, winner_id, status, is_bye,
            finished_at, round, table_number, created_at, updated_at,
            group_id, bracket_id, bracket_round, bracket_match
        FROM games');

        DB::statement('DROP TABLE games');
        DB::statement('ALTER TABLE games__bye_tmp RENAME TO games');

        DB::statement('CREATE INDEX games_competition_id_status_index ON games (competition_id, status)');
        DB::statement('CREATE INDEX games_status_index ON games (status)');
        DB::statement('CREATE INDEX games_round_index ON games (round)');

        DB::statement('PRAGMA foreign_keys=ON');
    }
};
