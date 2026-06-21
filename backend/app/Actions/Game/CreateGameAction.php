<?php

namespace App\Actions\Game;

use App\Enums\GameStatus;
use App\Models\Competition;
use App\Models\Game;
use App\Support\Game\GameFormatResolver;

final class CreateGameAction
{
    public function __invoke(array $payload): Game
    {
        $payload['status'] ??= GameStatus::Pending;
        $payload['winner_id'] ??= null;
        $payload['is_bye'] ??= false;

        if ($payload['is_bye']) {
            $payload['best_of'] = null;
            $payload['sets_to_win'] = null;
        } elseif (! array_key_exists('best_of', $payload) || ! array_key_exists('sets_to_win', $payload)) {
            $payload = $this->applyLegacyFormatFallback($payload);
        }

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

        $format = GameFormatResolver::fromLegacySetsToWin((int) $competition->sets_to_win);

        $payload['best_of'] ??= $format['best_of'];
        $payload['sets_to_win'] ??= $format['sets_to_win'];

        return $payload;
    }
}
