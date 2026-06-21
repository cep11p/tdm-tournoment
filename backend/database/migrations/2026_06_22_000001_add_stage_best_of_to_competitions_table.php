<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competitions', function (Blueprint $table): void {
            $table->unsignedSmallInteger('group_stage_best_of')->default(5)->after('points_per_set');
            $table->unsignedSmallInteger('knockout_stage_best_of')->default(5)->after('group_stage_best_of');
            $table->unsignedSmallInteger('semifinal_best_of')->default(7)->after('knockout_stage_best_of');
            $table->unsignedSmallInteger('final_best_of')->default(7)->after('semifinal_best_of');
        });

        DB::table('competitions')->orderBy('id')->each(function (object $competition): void {
            $legacyBestOf = max(1, ((int) $competition->sets_to_win * 2) - 1);

            DB::table('competitions')
                ->where('id', $competition->id)
                ->update([
                    'group_stage_best_of' => $legacyBestOf,
                    'knockout_stage_best_of' => $legacyBestOf,
                    'semifinal_best_of' => $legacyBestOf,
                    'final_best_of' => $legacyBestOf,
                ]);
        });
    }

    public function down(): void
    {
        Schema::table('competitions', function (Blueprint $table): void {
            $table->dropColumn([
                'group_stage_best_of',
                'knockout_stage_best_of',
                'semifinal_best_of',
                'final_best_of',
            ]);
        });
    }
};
