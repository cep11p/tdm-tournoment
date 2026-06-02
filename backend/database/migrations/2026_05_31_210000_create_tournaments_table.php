<?php

use App\Enums\TournamentStatus;
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
        Schema::create('tournaments', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('location');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status')->default(TournamentStatus::Draft->value)->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tournaments');
    }
};
