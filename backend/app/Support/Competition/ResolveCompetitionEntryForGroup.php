<?php

namespace App\Support\Competition;

use App\Enums\CompetitionType;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use Illuminate\Validation\ValidationException;

final class ResolveCompetitionEntryForGroup
{
    public function __construct(
        private readonly ResolveSinglesEntryForPlayer $resolveSinglesEntryForPlayer,
    ) {}

    /**
     * @param  array{player_id?: int, competition_entry_id?: int}  $payload
     */
    public function __invoke(Competition $competition, array $payload): CompetitionEntry
    {
        $competition->loadMissing('tournament');
        $isDoubles = $competition->type === CompetitionType::Doubles;

        if ($isDoubles) {
            if (! isset($payload['competition_entry_id'])) {
                throw ValidationException::withMessages([
                    'competition_entry_id' => ['Se requiere competition_entry_id para asignar una pareja al grupo.'],
                ]);
            }

            if (isset($payload['player_id'])) {
                throw ValidationException::withMessages([
                    'player_id' => ['No se puede asignar una pareja al grupo usando player_id.'],
                ]);
            }

            return $this->resolveByEntryId($competition, (int) $payload['competition_entry_id']);
        }

        if (isset($payload['competition_entry_id'])) {
            return $this->resolveByEntryId($competition, (int) $payload['competition_entry_id']);
        }

        if (isset($payload['player_id'])) {
            return ($this->resolveSinglesEntryForPlayer)($competition, (int) $payload['player_id']);
        }

        throw ValidationException::withMessages([
            'player_id' => ['Se requiere player_id o competition_entry_id.'],
        ]);
    }

    private function resolveByEntryId(Competition $competition, int $entryId): CompetitionEntry
    {
        $entry = CompetitionEntry::query()
            ->where('id', $entryId)
            ->where('competition_id', $competition->id)
            ->with('members')
            ->first();

        if ($entry === null) {
            throw ValidationException::withMessages([
                'competition_entry_id' => ['La participación no pertenece a esta competencia.'],
            ]);
        }

        if ($competition->type === CompetitionType::Doubles && $entry->members->count() !== 2) {
            throw ValidationException::withMessages([
                'competition_entry_id' => ['La participación no es una pareja válida para esta competencia.'],
            ]);
        }

        if ($competition->type === CompetitionType::Singles && $entry->members->count() !== 1) {
            throw ValidationException::withMessages([
                'competition_entry_id' => ['La participación no es un jugador válido para esta competencia.'],
            ]);
        }

        return $entry;
    }
}
