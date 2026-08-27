<?php

namespace App\Support\Game;

use App\Enums\GameStatus;
use App\Models\CompetitionEntry;
use App\Models\Game;
use Illuminate\Validation\ValidationException;

final class GameEntryInvariantGuard
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function assertCreate(array $payload): void
    {
        $competitionId = (int) ($payload['competition_id'] ?? 0);
        $entry1Id = self::nullableId($payload['entry1_id'] ?? null);
        $entry2Id = self::nullableId($payload['entry2_id'] ?? null);
        $winnerEntryId = self::nullableId($payload['winner_entry_id'] ?? null);
        $isBye = (bool) ($payload['is_bye'] ?? false);

        if ($competitionId <= 0) {
            throw ValidationException::withMessages([
                'competition_id' => ['El partido debe pertenecer a una competencia.'],
            ]);
        }

        if ($entry1Id === null) {
            throw ValidationException::withMessages([
                'entry1_id' => ['El lado 1 del partido es obligatorio.'],
            ]);
        }

        self::assertDistinctSides($entry1Id, $entry2Id);
        self::assertWinnerIsSide($entry1Id, $entry2Id, $winnerEntryId);
        self::assertByeOrComplete($isBye, $entry1Id, $entry2Id, $winnerEntryId, $payload['status'] ?? null);
        self::assertEntriesBelongToCompetition($competitionId, $entry1Id, $entry2Id, $winnerEntryId);
    }

    public static function assertWinnerIsSideOfGame(Game $game, ?int $winnerEntryId): void
    {
        self::assertWinnerIsSide(
            (int) $game->entry1_id,
            self::nullableId($game->entry2_id),
            $winnerEntryId,
        );
    }

    public static function assertDistinctSides(int $entry1Id, ?int $entry2Id): void
    {
        if ($entry2Id !== null && $entry1Id === $entry2Id) {
            throw ValidationException::withMessages([
                'entry2_id' => ['Un partido no puede enfrentar a la misma participación.'],
            ]);
        }
    }

    public static function assertWinnerIsSide(int $entry1Id, ?int $entry2Id, ?int $winnerEntryId): void
    {
        if ($winnerEntryId === null) {
            return;
        }

        if ($winnerEntryId !== $entry1Id && $winnerEntryId !== $entry2Id) {
            throw ValidationException::withMessages([
                'winner_entry_id' => ['El ganador debe ser uno de los dos lados del partido.'],
            ]);
        }
    }

    /**
     * @param  GameStatus|string|null  $status
     */
    public static function assertByeOrComplete(
        bool $isBye,
        int $entry1Id,
        ?int $entry2Id,
        ?int $winnerEntryId,
        mixed $status,
    ): void {
        if ($isBye) {
            if ($entry2Id !== null) {
                throw ValidationException::withMessages([
                    'entry2_id' => ['Un partido con BYE no puede tener un segundo lado.'],
                ]);
            }

            if ($winnerEntryId !== $entry1Id) {
                throw ValidationException::withMessages([
                    'winner_entry_id' => ['El ganador de un BYE debe ser el lado 1.'],
                ]);
            }

            $statusValue = $status instanceof GameStatus ? $status : $status;

            if ($statusValue !== null && $statusValue !== GameStatus::Finished && $statusValue !== GameStatus::Finished->value) {
                throw ValidationException::withMessages([
                    'status' => ['Un partido con BYE debe quedar finalizado.'],
                ]);
            }

            return;
        }

        if ($entry2Id === null) {
            throw ValidationException::withMessages([
                'entry2_id' => ['El lado 2 del partido es obligatorio.'],
            ]);
        }
    }

    public static function assertEntriesBelongToCompetition(
        int $competitionId,
        int $entry1Id,
        ?int $entry2Id,
        ?int $winnerEntryId,
    ): void {
        $entryIds = array_values(array_unique(array_filter(
            [$entry1Id, $entry2Id, $winnerEntryId],
            fn (?int $entryId): bool => $entryId !== null,
        )));

        $entries = CompetitionEntry::query()
            ->whereIn('id', $entryIds)
            ->get()
            ->keyBy('id');

        foreach ($entryIds as $entryId) {
            $entry = $entries->get($entryId);

            if ($entry === null) {
                throw ValidationException::withMessages([
                    'entry1_id' => ['La participación no existe.'],
                ]);
            }

            if ((int) $entry->competition_id !== $competitionId) {
                throw ValidationException::withMessages([
                    'competition_id' => ['Un partido no puede enfrentar participaciones de otra competencia.'],
                ]);
            }
        }
    }

    private static function nullableId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }
}
