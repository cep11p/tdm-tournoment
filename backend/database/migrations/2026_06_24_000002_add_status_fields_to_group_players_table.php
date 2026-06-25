<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_players', function (Blueprint $table): void {
            $table->string('status')->default('active')->after('player_id');
            $table->string('status_reason')->nullable()->after('status');
            $table->text('status_notes')->nullable()->after('status_reason');
            $table->timestamp('status_changed_at')->nullable()->after('status_notes');
        });
    }

    public function down(): void
    {
        Schema::table('group_players', function (Blueprint $table): void {
            $table->dropColumn([
                'status',
                'status_reason',
                'status_notes',
                'status_changed_at',
            ]);
        });
    }
};
