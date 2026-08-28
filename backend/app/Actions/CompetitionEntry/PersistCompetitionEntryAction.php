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
     *     player_ids?: list<int>
     * }  $payload
     */
    public function __invoke(array $payload): CompetitionEntry
    {
        $competition = Competition::query()->findOrFail($payload['competition_id']);
        $competition->loadMissing('tournament');
        TournamentLifecycleGuard::ensureMutableForCompetition($competition);
        CompetitionEntryGuard::ensureEditable($competition);

        $hasPlayerId = array_key_exists('player_id', $payload);
        $hasPlayerIds = array_key_exists('player_ids', $payload);

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

        $playersErrorKey = $hasPlayerIds ? 'player_ids' : 'player_id';

        $playerIds = $hasPlayerIds
            ? array_map('intval', $payload['player_ids'])
            : [(int) $payload['player_id']];

        $this->validateMemberCount($competition->type, $playerIds, $playersErrorKey);
        $this->validateDistinctPlayerIds($playerIds, $playersErrorKey);
        $this->validatePlayersExist($playerIds, $playersErrorKey);
        $this->validatePlayersNotAlreadyRegistered($competition->id, $playerIds, $playersErrorKey);

        return DB::transaction(function () use ($competition, $playerIds, $playersErrorKey): CompetitionEntry {
            try {
                $entry = CompetitionEntry::query()->create([
                    'competition_id' => $competition->id,
                    'status' => CompetitionEntryStatus::Active,
                ]);

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
    private function validateMemberCount(CompetitionType $type, array $playerIds, string $errorKey): void
    {
        $count = count($playerIds);
        $expected = match ($type) {
            CompetitionType::Singles => 1,
            CompetitionType::Doubles => 2,
        };

        if ($count === $expected) {
            return;
        }

        $message = match ($type) {
            CompetitionType::Singles => 'Una competencia de singles requiere exactamente 1 jugador.',
            CompetitionType::Doubles => 'Una competencia de dobles requiere exactamente 2 jugadores.',
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
}
