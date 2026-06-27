<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table): void {
            $table->boolean('active')->default(true)->after('nickname');
            $table->index('active');
        });

        Schema::table('players', function (Blueprint $table): void {
            $table->unique('nickname');
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table): void {
            $table->dropUnique(['nickname']);
            $table->dropIndex(['active']);
            $table->dropColumn('active');
        });
    }
};
