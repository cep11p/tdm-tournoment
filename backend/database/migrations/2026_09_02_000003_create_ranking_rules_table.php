<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ranking_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ranking_id')
                ->constrained('rankings')
                ->cascadeOnDelete();
            $table->string('name');
            $table->string('source');
            $table->unsignedSmallInteger('position')->nullable();
            $table->unsignedInteger('points');
            $table->unsignedInteger('priority')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['ranking_id', 'source', 'active'], 'ranking_rules_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ranking_rules');
    }
};
