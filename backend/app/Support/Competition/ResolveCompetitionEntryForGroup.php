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
        $type = $competition->type instanceof CompetitionType
            ? $competition->type
            : CompetitionType::from((string) $competition->type);

        if ($type->isMultiMember()) {
            if (! isset($payload['competition_entry_id'])) {
                $label = $type->isTeam() ? 'equipo' : 'pareja';

                throw ValidationException::withMessages([
                    'competition_entry_id' => ["Se requiere competition_entry_id para asignar un {$label} al grupo."],
                ]);
            }

            if (isset($payload['player_id'])) {
                $label = $type->isTeam() ? 'equipo' : 'pareja';

                throw ValidationException::withMessages([
                    'player_id' => ["No se puede asignar un {$label} al grupo usando player_id."],
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

        $expectedCount = $competition->expectedMemberCount();
        $actualCount = $entry->members->count();

        if ($actualCount !== $expectedCount) {
            $type = $competition->type instanceof CompetitionType
                ? $competition->type
                : CompetitionType::from((string) $competition->type);

            $message = match ($type) {
                CompetitionType::Singles => 'La participación no es un jugador válido para esta competencia.',
                CompetitionType::Doubles => 'La participación no es una pareja válida para esta competencia.',
                CompetitionType::Team => 'La participación no es un equipo válido para esta competencia.',
            };

            throw ValidationException::withMessages([
                'competition_entry_id' => [$message],
            ]);
        }

        return $entry;
    }
}
