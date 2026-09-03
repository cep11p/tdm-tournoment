<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competition_final_standings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('competition_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->unsignedBigInteger('competition_entry_id');
            $table->unsignedSmallInteger('position');
            $table->unsignedSmallInteger('position_range_end');
            $table->string('source');
            $table->string('display_name_snapshot');
            $table->timestamps();

            $table->unique(['competition_id', 'competition_entry_id'], 'cfs_competition_entry_unique');
            $table->index(['competition_id', 'position'], 'cfs_competition_position_index');

            $table->foreign(['competition_entry_id', 'competition_id'], 'cfs_entry_competition_fk')
                ->references(['id', 'competition_id'])
                ->on('competition_entries')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_final_standings');
    }
};
