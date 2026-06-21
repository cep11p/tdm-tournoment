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
            $table->unsignedSmallInteger('best_of')->nullable()->after('is_bye');
            $table->unsignedSmallInteger('sets_to_win')->nullable()->after('best_of');
        });

        DB::table('games')
            ->orderBy('id')
            ->each(function (object $game): void {
                if ((bool) $game->is_bye) {
                    return;
                }

                $competition = DB::table('competitions')
                    ->where('id', $game->competition_id)
                    ->first();

                if ($competition === null) {
                    return;
                }

                $legacySetsToWin = (int) $competition->sets_to_win;
                $legacyBestOf = max(1, ($legacySetsToWin * 2) - 1);

                DB::table('games')
                    ->where('id', $game->id)
                    ->update([
                        'sets_to_win' => $legacySetsToWin,
                        'best_of' => $legacyBestOf,
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table): void {
            $table->dropColumn(['best_of', 'sets_to_win']);
        });
    }
};
