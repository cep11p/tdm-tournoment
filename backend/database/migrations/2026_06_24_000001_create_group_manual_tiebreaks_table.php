<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_manual_tiebreaks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('reason');
            $table->text('notes')->nullable();
            $table->timestamp('applied_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_manual_tiebreaks');
    }
};
