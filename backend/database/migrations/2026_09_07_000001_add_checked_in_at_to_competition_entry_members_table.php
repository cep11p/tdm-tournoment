<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competition_entry_members', function (Blueprint $table): void {
            $table->timestamp('checked_in_at')->nullable()->after('member_order');
        });
    }

    public function down(): void
    {
        Schema::table('competition_entry_members', function (Blueprint $table): void {
            $table->dropColumn('checked_in_at');
        });
    }
};
