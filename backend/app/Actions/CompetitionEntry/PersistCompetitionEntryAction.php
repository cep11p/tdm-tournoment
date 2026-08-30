<?php

namespace App\Actions\CompetitionEntry;

use App\Enums\CompetitionEntryStatus;
use App\Enums\CompetitionType;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\CompetitionEntryMember;
use App\Models\Player;
use App\Support\Competition\CompetitionEntryGuard;
use App\Support\Tournament\TournamentLifecycleGuard;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PersistCompetitionEntryAction
{
    /**
     * @param  array{
     *     competition_id: int,
     *     player_id?: int,
     *     player_ids?: list<int>,
     *     name?: string
     * }  $payload
     */
    public function __invoke(array $payload): CompetitionEntry
    {
        $competition = Competition::query()->findOrFail($payload['competition_id']);
        $competition->loadMissing('tournament');
        TournamentLifecycleGuard::ensureMutableForCompetition($competition);
        CompetitionEntryGuard::ensureEditable($competition);

        $type = $competition->type instanceof CompetitionType
            ? $competition->type
            : CompetitionType::from((string) $competition->type);

        $hasPlayerId = array_key_exists('player_id', $payload);
        $hasPlayerIds = array_key_exists('player_ids', $payload);
        $hasName = array_key_exists('name', $payload);

        if ($hasPlayerId && $hasPlayerIds) {
            throw ValidationException::withMessages([
                'player_id' => ['No se puede enviar player_id y player_ids al mismo tiempo.'],
                'player_ids' => ['No se puede enviar player_id y player_ids al mismo tiempo.'],
            ]);
        }

        if (! $hasPlayerId && ! $hasPlayerIds) {
            throw ValidationException::withMessages([
                'player_ids' => ['Debe indicar al menos un jugador.'],
            ]);
        }

        if ($type->isTeam() && ! $hasName) {
            throw ValidationException::withMessages([
                'name' => ['El nombre del equipo es obligatorio.'],
            ]);
        }

        if (! $type->isTeam() && $hasName) {
            throw ValidationException::withMessages([
                'name' => ['No se puede enviar el nombre del equipo en esta competencia.'],
            ]);
        }

        $playersErrorKey = $hasPlayerIds ? 'player_ids' : 'player_id';

        $playerIds = $hasPlayerIds
            ? array_map('intval', $payload['player_ids'])
            : [(int) $payload['player_id']];

        $teamName = $type->isTeam() ? trim((string) $payload['name']) : null;

        if ($type->isTeam() && ($teamName === null || $teamName === '')) {
            throw ValidationException::withMessages([
                'name' => ['El nombre del equipo es obligatorio.'],
            ]);
        }

        $this->validateMemberCount($competition, $playerIds, $playersErrorKey);
        $this->validateDistinctPlayerIds($playerIds, $playersErrorKey);
        $this->validatePlayersExist($playerIds, $playersErrorKey);
        $this->validatePlayersNotAlreadyRegistered($competition->id, $playerIds, $playersErrorKey);

        if ($type->isTeam() && $teamName !== null) {
            $this->validateTeamNameUnique($competition->id, $teamName);
        }

        return DB::transaction(function () use ($competition, $playerIds, $playersErrorKey, $teamName, $type): CompetitionEntry {
            try {
                $entryAttributes = [
                    'competition_id' => $competition->id,
                    'status' => CompetitionEntryStatus::Active,
                ];

                if ($type->isTeam()) {
                    $entryAttributes['display_name'] = $teamName;
                }

                $entry = CompetitionEntry::query()->create($entryAttributes);

                foreach ($playerIds as $index => $playerId) {
                    CompetitionEntryMember::query()->create([
                        'competition_entry_id' => $entry->id,
                        'competition_id' => $competition->id,
                        'player_id' => $playerId,
                        'member_order' => $index + 1,
                    ]);
                }

                return $entry->load('members');
            } catch (QueryException $exception) {
                if ((string) $exception->getCode() === '23000') {
                    if ($type->isTeam()) {
                        throw ValidationException::withMessages([
                            'name' => ['Ya existe un equipo con ese nombre en esta competencia.'],
                        ]);
                    }

                    throw ValidationException::withMessages([
                        $playersErrorKey => ['El jugador ya está inscripto en esta competencia.'],
                    ]);
                }

                throw $exception;
            }
        });
    }

    /**
     * @param  list<int>  $playerIds
     */
    private function validateMemberCount(Competition $competition, array $playerIds, string $errorKey): void
    {
        $count = count($playerIds);
        $expected = $competition->expectedMemberCount();

        if ($count === $expected) {
            return;
        }

        $type = $competition->type instanceof CompetitionType
            ? $competition->type
            : CompetitionType::from((string) $competition->type);

        $message = match ($type) {
            CompetitionType::Singles => 'Una competencia de singles requiere exactamente 1 jugador.',
            CompetitionType::Doubles => 'Una competencia de dobles requiere exactamente 2 jugadores.',
            CompetitionType::Team => sprintf(
                'Una competencia por equipos requiere exactamente %d integrantes.',
                $expected,
            ),
        };

        throw ValidationException::withMessages([
            $errorKey => [$message],
        ]);
    }

    /**
     * @param  list<int>  $playerIds
     */
    private function validateDistinctPlayerIds(array $playerIds, string $errorKey): void
    {
        if (count($playerIds) === count(array_unique($playerIds))) {
            return;
        }

        throw ValidationException::withMessages([
            $errorKey => ['Los jugadores de una inscripción deben ser distintos.'],
        ]);
    }

    /**
     * @param  list<int>  $playerIds
     */
    private function validatePlayersExist(array $playerIds, string $errorKey): void
    {
        $existingCount = Player::query()->whereIn('id', $playerIds)->count();

        if ($existingCount === count($playerIds)) {
            return;
        }

        throw ValidationException::withMessages([
            $errorKey => ['Uno o más jugadores no existen.'],
        ]);
    }

    /**
     * @param  list<int>  $playerIds
     */
    private function validatePlayersNotAlreadyRegistered(int $competitionId, array $playerIds, string $errorKey): void
    {
        $alreadyRegistered = CompetitionEntryMember::query()
            ->where('competition_id', $competitionId)
            ->whereIn('player_id', $playerIds)
            ->pluck('player_id')
            ->all();

        if ($alreadyRegistered === []) {
            return;
        }

        throw ValidationException::withMessages([
            $errorKey => ['El jugador ya está inscripto en esta competencia.'],
        ]);
    }

    private function validateTeamNameUnique(int $competitionId, string $teamName): void
    {
        $exists = CompetitionEntry::query()
            ->where('competition_id', $competitionId)
            ->where('display_name', $teamName)
            ->exists();

        if (! $exists) {
            return;
        }

        throw ValidationException::withMessages([
            'name' => ['Ya existe un equipo con ese nombre en esta competencia.'],
        ]);
    }
}
