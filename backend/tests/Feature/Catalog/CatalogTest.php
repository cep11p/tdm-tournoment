<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Club;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    public function test_lists_active_categories_ordered_by_name(): void
    {
        Category::query()->create([
            'name' => 'Zeta',
            'slug' => 'zeta-test',
            'active' => true,
        ]);
        Category::query()->create([
            'name' => 'Inactiva',
            'slug' => 'inactiva-test',
            'active' => false,
        ]);

        $response = $this->getJson('/api/v1/categories');

        $response
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'slug', 'active'],
                ],
            ]);

        $names = collect($response->json('data'))->pluck('name')->all();

        $this->assertSame($names, collect($names)->sort()->values()->all());
        $this->assertNotContains('Inactiva', $names);
    }

    public function test_lists_active_clubs_ordered_by_name(): void
    {
        Club::query()->create([
            'name' => 'Zeta Club',
            'active' => true,
        ]);
        Club::query()->create([
            'name' => 'Club Inactivo',
            'active' => false,
        ]);

        $response = $this->getJson('/api/v1/clubs');

        $response
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'active'],
                ],
            ]);

        $names = collect($response->json('data'))->pluck('name')->all();

        $this->assertSame($names, collect($names)->sort()->values()->all());
        $this->assertNotContains('Club Inactivo', $names);
    }

    public function test_seeded_initial_categories_are_available(): void
    {
        $response = $this->getJson('/api/v1/categories');

        $response->assertOk();

        $slugs = collect($response->json('data'))->pluck('slug')->all();

        $this->assertContains('primera', $slugs);
        $this->assertContains('segunda', $slugs);
        $this->assertContains('tercera', $slugs);
        $this->assertContains('cuarta', $slugs);
        $this->assertContains('libre', $slugs);
    }
}
