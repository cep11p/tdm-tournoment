<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competition_entries', function (Blueprint $table): void {
            $table->string('display_name')->nullable()->after('status');
            $table->unique(['competition_id', 'display_name'], 'ce_competition_display_name_unique');
        });
    }

    public function down(): void
    {
        Schema::table('competition_entries', function (Blueprint $table): void {
            $table->dropUnique('ce_competition_display_name_unique');
            $table->dropColumn('display_name');
        });
    }
};
