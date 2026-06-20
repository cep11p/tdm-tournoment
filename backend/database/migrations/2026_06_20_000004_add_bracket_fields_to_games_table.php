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
        Schema::table('games', function (Blueprint $table): void {
            $table->foreignId('bracket_id')
                ->nullable()
                ->after('group_id')
                ->constrained()
                ->nullOnDelete();
            $table->unsignedSmallInteger('bracket_round')->nullable()->after('round');
            $table->unsignedSmallInteger('bracket_match')->nullable()->after('bracket_round');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('games', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('bracket_id');
            $table->dropColumn(['bracket_round', 'bracket_match']);
        });
    }
};
