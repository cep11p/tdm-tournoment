<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competitions', function (Blueprint $table): void {
            $table->foreignId('category_id')
                ->nullable()
                ->after('category')
                ->constrained('categories')
                ->nullOnDelete();
        });

        $categoriesBySlug = DB::table('categories')
            ->pluck('id', 'slug')
            ->all();

        DB::table('competitions')
            ->select(['id', 'category'])
            ->orderBy('id')
            ->chunkById(100, function ($competitions) use ($categoriesBySlug): void {
                foreach ($competitions as $competition) {
                    $normalized = mb_strtolower(trim((string) $competition->category));

                    if ($normalized === '' || ! isset($categoriesBySlug[$normalized])) {
                        continue;
                    }

                    DB::table('competitions')
                        ->where('id', $competition->id)
                        ->update(['category_id' => $categoriesBySlug[$normalized]]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('competitions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('category_id');
        });
    }
};
