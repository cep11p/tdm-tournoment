<?php

use App\Enums\CompetitionFormat;
use App\Enums\CompetitionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('competitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tournament_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->default(CompetitionType::Singles->value);
            $table->string('category');
            $table->string('format')->default(CompetitionFormat::Manual->value);
            $table->unsignedSmallInteger('sets_to_win');
            $table->unsignedSmallInteger('points_per_set');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('competitions');
    }
};
