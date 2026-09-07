<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playing_tables', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tournament_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('name')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order');
            $table->timestamps();

            $table->unique(['tournament_id', 'number']);
            $table->index(['tournament_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playing_tables');
    }
};
