<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('competitions')
            ->where('format', 'manual')
            ->update(['format' => 'groups_knockout']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('competitions')
            ->where('format', 'groups_knockout')
            ->update(['format' => 'manual']);
    }
};
