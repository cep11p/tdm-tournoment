<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rankings', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('competition_type');
            $table->foreignId('category_id')
                ->nullable()
                ->constrained('categories')
                ->restrictOnDelete();
            $table->string('season');
            $table->boolean('active')->default(true);
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->timestamps();

            $table->index(['competition_type', 'active'], 'rankings_type_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rankings');
    }
};
