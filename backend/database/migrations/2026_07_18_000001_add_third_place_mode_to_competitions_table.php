<?php

use App\Enums\ThirdPlaceMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competitions', function (Blueprint $table): void {
            $table->string('third_place_mode')
                ->default(ThirdPlaceMode::None->value)
                ->after('final_best_of');
        });

        DB::table('competitions')->update([
            'third_place_mode' => ThirdPlaceMode::None->value,
        ]);
    }

    public function down(): void
    {
        Schema::table('competitions', function (Blueprint $table): void {
            $table->dropColumn('third_place_mode');
        });
    }
};
