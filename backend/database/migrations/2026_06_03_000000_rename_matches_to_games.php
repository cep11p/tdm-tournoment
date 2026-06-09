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
        if (Schema::hasTable('matches') && ! Schema::hasTable('games')) {
            Schema::rename('matches', 'games');
        }

        if (Schema::hasTable('games')) {
            Schema::table('games', function (Blueprint $table): void {
                if (! Schema::hasColumn('games', 'finished_at')) {
                    $table->timestamp('finished_at')->nullable()->after('status');
                }

                if (! Schema::hasColumn('games', 'table_number')) {
                    $table->unsignedSmallInteger('table_number')->nullable()->after('round');
                }
            });
        }

        if (! Schema::hasTable('match_sets')) {
            return;
        }

        if (Schema::hasTable('game_sets')) {
            return;
        }

        Schema::table('match_sets', function (Blueprint $table): void {
            $table->dropForeign(['match_id']);
        });

        Schema::rename('match_sets', 'game_sets');

        Schema::table('game_sets', function (Blueprint $table): void {
            $table->renameColumn('match_id', 'game_id');
        });

        Schema::table('game_sets', function (Blueprint $table): void {
            $table->foreign('game_id')
                ->references('id')
                ->on('games')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('game_sets') && ! Schema::hasTable('match_sets')) {
            Schema::table('game_sets', function (Blueprint $table): void {
                $table->dropForeign(['game_id']);
            });

            Schema::table('game_sets', function (Blueprint $table): void {
                $table->renameColumn('game_id', 'match_id');
            });

            Schema::rename('game_sets', 'match_sets');

            Schema::table('match_sets', function (Blueprint $table): void {
                $table->foreign('match_id')
                    ->references('id')
                    ->on('games')
                    ->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('games') && ! Schema::hasTable('matches')) {
            Schema::table('games', function (Blueprint $table): void {
                if (Schema::hasColumn('games', 'finished_at')) {
                    $table->dropColumn('finished_at');
                }

                if (Schema::hasColumn('games', 'table_number')) {
                    $table->dropColumn('table_number');
                }
            });

            Schema::rename('games', 'matches');
        }
    }
};
