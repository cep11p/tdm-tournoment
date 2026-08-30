<?php

namespace Database\Factories;

use App\Models\TeamTieFormat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamTieFormat>
 */
class TeamTieFormatFactory extends Factory
{
    protected $model = TeamTieFormat::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->optional()->sentence(),
            'victories_required' => 3,
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['active' => false]);
    }
}
