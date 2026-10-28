<?php

use App\Enums\RatingRuleVersionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rating_rule_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rating_system_id')->constrained('rating_systems');
            $table->unsignedInteger('version');
            $table->string('status')->default(RatingRuleVersionStatus::Draft->value);
            $table->json('rules');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['rating_system_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rating_rule_versions');
    }
};
