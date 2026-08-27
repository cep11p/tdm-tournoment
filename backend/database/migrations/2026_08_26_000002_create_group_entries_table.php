<?php

use App\Enums\GroupPlayerStatus;
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
        Schema::create('group_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('competition_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->unsignedBigInteger('competition_entry_id');
            $table->string('status')->default(GroupPlayerStatus::Active->value);
            $table->string('status_reason')->nullable();
            $table->text('status_notes')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();

            $table->unique(['group_id', 'competition_entry_id'], 'ge_group_entry_unique');
            $table->unique(['competition_id', 'competition_entry_id'], 'ge_competition_entry_unique');

            $table->foreign(['competition_entry_id', 'competition_id'], 'ge_entry_competition_fk')
                ->references(['id', 'competition_id'])
                ->on('competition_entries')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('group_entries');
    }
};
