<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('type', ['singles'])->default('singles');
            $table->string('category');
            $table->enum('format', ['manual'])->default('manual');
            $table->unsignedTinyInteger('sets_to_win')->default(3);
            $table->unsignedTinyInteger('points_per_set')->default(11);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitions');
    }
};
