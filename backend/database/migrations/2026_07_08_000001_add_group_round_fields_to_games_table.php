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
            $table->unsignedSmallInteger('group_round')->nullable()->after('bracket_match');
            $table->unsignedSmallInteger('group_match')->nullable()->after('group_round');

            $table->index(['group_id', 'group_round', 'group_match']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('games', function (Blueprint $table): void {
            $table->dropIndex(['group_id', 'group_round', 'group_match']);
            $table->dropColumn(['group_round', 'group_match']);
        });
    }
};
