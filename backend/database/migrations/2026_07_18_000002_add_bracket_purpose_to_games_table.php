<?php

use App\Enums\BracketGamePurpose;
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
        Schema::table('games', function (Blueprint $table): void {
            $table->string('bracket_purpose')
                ->default(BracketGamePurpose::Main->value)
                ->after('bracket_match');
        });

        DB::table('games')->update([
            'bracket_purpose' => BracketGamePurpose::Main->value,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('games', function (Blueprint $table): void {
            $table->dropColumn('bracket_purpose');
        });
    }
};
