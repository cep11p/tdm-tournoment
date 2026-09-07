<?php

namespace App\Actions\PlayingTable;

use App\Models\PlayingTable;
use App\Models\Tournament;
use App\Support\Tournament\TournamentLifecycleGuard;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

final class CreatePlayingTableAction
{
    public const DUPLICATE_NUMBER_MESSAGE = 'Ya existe una mesa con ese número en el torneo.';

    /**
     * @param  array{number: int, name?: string|null, active?: bool, sort_order?: int}  $payload
     */
    public function __invoke(Tournament $tournament, array $payload): PlayingTable
    {
        TournamentLifecycleGuard::ensureMutable($tournament);

        $number = (int) $payload['number'];

        try {
            return PlayingTable::query()->create([
                'tournament_id' => $tournament->id,
                'number' => $number,
                'name' => $payload['name'] ?? null,
                'active' => array_key_exists('active', $payload) ? (bool) $payload['active'] : true,
                'sort_order' => array_key_exists('sort_order', $payload)
                    ? (int) $payload['sort_order']
                    : $number,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'number' => [self::DUPLICATE_NUMBER_MESSAGE],
            ]);
        }
    }
}
