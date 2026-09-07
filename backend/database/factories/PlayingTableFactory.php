<?php

namespace Database\Factories;

use App\Enums\TournamentStatus;
use App\Models\PlayingTable;
use App\Models\Tournament;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<PlayingTable>
 */
class PlayingTableFactory extends Factory
{
    protected $model = PlayingTable::class;

    public function definition(): array
    {
        $number = fake()->unique()->numberBetween(1, 999);

        return [
            'tournament_id' => fn (): int => Tournament::query()->create([
                'name' => fake()->unique()->words(3, true),
                'location' => 'Club Test',
                'start_date' => Carbon::today()->toDateString(),
                'status' => TournamentStatus::Draft,
            ])->id,
            'number' => $number,
            'name' => null,
            'active' => true,
            'sort_order' => $number,
        ];
    }

    public function named(string $name): static
    {
        return $this->state(fn (): array => ['name' => $name]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['active' => false]);
    }
}
