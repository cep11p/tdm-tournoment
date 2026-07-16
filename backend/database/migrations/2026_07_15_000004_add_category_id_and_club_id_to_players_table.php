<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table): void {
            $table->foreignId('category_id')
                ->nullable()
                ->after('nickname')
                ->constrained('categories')
                ->nullOnDelete();

            $table->foreignId('club_id')
                ->nullable()
                ->after('category_id')
                ->constrained('clubs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('club_id');
            $table->dropConstrainedForeignId('category_id');
        });
    }
};
