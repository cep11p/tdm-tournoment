<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competitions', function (Blueprint $table): void {
            $table->foreignId('team_tie_format_id')
                ->nullable()
                ->after('team_size')
                ->constrained('team_tie_formats')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('competitions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('team_tie_format_id');
        });
    }
};
