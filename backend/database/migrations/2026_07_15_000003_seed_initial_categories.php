<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @var array<int, array{name: string, slug: string}>
     */
    private const INITIAL_CATEGORIES = [
        ['name' => 'Primera', 'slug' => 'primera'],
        ['name' => 'Segunda', 'slug' => 'segunda'],
        ['name' => 'Tercera', 'slug' => 'tercera'],
        ['name' => 'Cuarta', 'slug' => 'cuarta'],
        ['name' => 'Libre', 'slug' => 'libre'],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::INITIAL_CATEGORIES as $category) {
            DB::table('categories')->insert([
                'name' => $category['name'],
                'slug' => $category['slug'],
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('categories')->whereIn('slug', array_column(self::INITIAL_CATEGORIES, 'slug'))->delete();
    }
};
