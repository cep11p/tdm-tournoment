<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('registrations');
        Schema::dropIfExists('group_players');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Legacy tables are not restored; use migrate:fresh for local development.
    }
};
