<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table): void {
            $table->timestamp('closed_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table): void {
            $table->dropColumn('closed_at');
        });
    }
};
