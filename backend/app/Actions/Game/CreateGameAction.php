<?php

namespace App\Actions\Game;

use App\Enums\BracketGamePurpose;
use App\Enums\GameStatus;
use App\Models\Competition;
use App\Models\Game;
use App\Support\Game\GameEntryInvariantGuard;
use App\Support\Game\GameFormatResolver;

final class CreateGameAction
{
    public function __invoke(array $payload): Game
    {
        $payload['status'] ??= GameStatus::Pending;
        $payload['is_bye'] ??= false;
        $payload['bracket_purpose'] ??= BracketGamePurpose::Main;
        $payload['entry2_id'] = $this->nullableId($payload['entry2_id'] ?? null);
        $payload['winner_entry_id'] = $this->nullableId($payload['winner_entry_id'] ?? null);

        if ($payload['is_bye']) {
            $payload['entry2_id'] = null;
            $payload['winner_entry_id'] = (int) $payload['entry1_id'];
            $payload['status'] = GameStatus::Finished;
            $payload['finished_at'] ??= now();
            $payload['best_of'] = null;
            $payload['sets_to_win'] = null;
        } elseif (! array_key_exists('best_of', $payload) || ! array_key_exists('sets_to_win', $payload)) {
            $payload = $this->applyLegacyFormatFallback($payload);
        }

        GameEntryInvariantGuard::assertCreate($payload);

        unset($payload['player1_id'], $payload['player2_id'], $payload['winner_id']);

        return Game::query()->create($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyLegacyFormatFallback(array $payload): array
    {
        $competitionId = (int) ($payload['competition_id'] ?? 0);
        $competition = Competition::query()->find($competitionId);

        if ($competition === null) {
            return $payload;
        }

        $format = GameFormatResolver::resolveForGroup($competition);

        $payload['best_of'] ??= $format['best_of'];
        $payload['sets_to_win'] ??= $format['sets_to_win'];

        return $payload;
    }

    private function nullableId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }
}
