<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_ties', function (Blueprint $table): void {
            $table->foreignId('bracket_id')
                ->nullable()
                ->after('group_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('bracket_round')->nullable()->after('group_match');
            $table->unsignedSmallInteger('bracket_match')->nullable()->after('bracket_round');
            $table->string('bracket_purpose')->nullable()->after('bracket_match');
            $table->string('round')->nullable()->after('bracket_purpose');

            $table->index(['competition_id', 'bracket_id']);
            $table->unique(
                ['bracket_id', 'bracket_purpose', 'bracket_round', 'bracket_match'],
                'team_ties_bracket_position_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('team_ties', function (Blueprint $table): void {
            $table->dropUnique('team_ties_bracket_position_unique');
            $table->dropIndex(['competition_id', 'bracket_id']);
            $table->dropConstrainedForeignId('bracket_id');
            $table->dropColumn([
                'bracket_round',
                'bracket_match',
                'bracket_purpose',
                'round',
            ]);
        });
    }
};
