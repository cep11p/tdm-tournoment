<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ranking_rules', function (Blueprint $table): void {
            $table->unsignedSmallInteger('position_key')
                ->storedAs('COALESCE(position, 0)');

            $table->unique(
                ['ranking_id', 'source', 'position_key'],
                'ranking_rules_structural_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('ranking_rules', function (Blueprint $table): void {
            $table->dropUnique('ranking_rules_structural_unique');
            $table->dropColumn('position_key');
        });
    }
};
