<?php

namespace App\Actions\Group;

use App\Enums\GameStatus;
use App\Enums\GroupPlayerStatus;
use App\Enums\GroupPlayerStatusReason;
use App\Models\Group;
use App\Models\GroupPlayer;
use App\Support\Competition\CompetitionFormatGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SetGroupPlayerStatusAction
{
    /**
     * @param  array{
     *     player_id: int,
     *     status: GroupPlayerStatus,
     *     reason?: ?GroupPlayerStatusReason,
     *     notes?: ?string
     * }  $payload
     */
    public function __invoke(Group $group, array $payload): GroupPlayer
    {
        $group->loadMissing('competition');

        CompetitionFormatGuard::ensureGroupStage($group->competition);

        if ($group->competition->brackets()->exists()) {
            throw ValidationException::withMessages([
                'group' => ['No se puede cambiar el estado del jugador cuando ya existe un cuadro eliminatorio.'],
            ]);
        }

        $playerId = (int) $payload['player_id'];

        $groupPlayer = GroupPlayer::query()
            ->where('group_id', $group->id)
            ->where('player_id', $playerId)
            ->first();

        if ($groupPlayer === null) {
            throw ValidationException::withMessages([
                'player_id' => ['El jugador no pertenece al grupo.'],
            ]);
        }

        if (! $groupPlayer->isActive()) {
            throw ValidationException::withMessages([
                'player_id' => ['El jugador ya no está activo en el grupo.'],
            ]);
        }

        $newStatus = $payload['status'];

        if ($newStatus === GroupPlayerStatus::Active) {
            throw ValidationException::withMessages([
                'status' => ['No se permite reactivar jugadores en esta versión.'],
            ]);
        }

        return DB::transaction(function () use ($group, $groupPlayer, $payload, $newStatus, $playerId): GroupPlayer {
            $groupPlayer->update([
                'status' => $newStatus,
                'status_reason' => $payload['reason'] ?? null,
                'status_notes' => $payload['notes'] ?? null,
                'status_changed_at' => now(),
            ]);

            $this->closePendingGroupGamesForPlayer($group, $playerId);

            return $groupPlayer->fresh([
                'player:id,first_name,last_name,nickname',
            ]);
        });
    }

    private function closePendingGroupGamesForPlayer(Group $group, int $playerId): void
    {
        $openGames = $group->games()
            ->whereIn('status', [GameStatus::Pending, GameStatus::InProgress])
            ->where(function ($query) use ($playerId): void {
                $query->where('player1_id', $playerId)
                    ->orWhere('player2_id', $playerId);
            })
            ->get();

        foreach ($openGames as $game) {
            $opponentId = (int) $game->player1_id === $playerId
                ? (int) $game->player2_id
                : (int) $game->player1_id;

            if ($opponentId <= 0) {
                continue;
            }

            $game->update([
                'status' => GameStatus::Finished,
                'winner_id' => $opponentId,
                'finished_at' => now(),
            ]);
        }
    }
}
