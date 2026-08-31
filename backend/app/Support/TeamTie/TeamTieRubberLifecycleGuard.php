<?php

namespace App\Support\TeamTie;

use App\Enums\CompetitionType;
use App\Enums\GameStatus;
use App\Models\Competition;
use App\Models\TeamTie;
use App\Models\TeamTieGameMember;
use Illuminate\Validation\ValidationException;

final class TeamTieRubberLifecycleGuard
{
    public static function ensureRegenerationAllowed(Competition $competition): void
    {
        $type = $competition->type instanceof CompetitionType
            ? $competition->type
            : CompetitionType::from((string) $competition->type);

        if ($type !== CompetitionType::Team) {
            return;
        }

        $hasLineup = TeamTieGameMember::query()
            ->whereHas('teamTieGame.teamTie', fn ($query) => $query->where('competition_id', $competition->id))
            ->exists();

        if ($hasLineup) {
            throw ValidationException::withMessages([
                'competition' => ['No se pueden regenerar los grupos porque ya hay lineups configurados en enfrentamientos.'],
            ]);
        }

        $hasStartedRubbers = TeamTie::query()
            ->where('competition_id', $competition->id)
            ->whereHas('teamTieGames.game', function ($query): void {
                $query->where(function ($query): void {
                    $query->where('status', '!=', GameStatus::Pending)
                        ->orWhereHas('sets');
                });
            })
            ->exists();

        if ($hasStartedRubbers) {
            throw ValidationException::withMessages([
                'competition' => ['No se pueden regenerar los grupos porque ya hay partidos internos iniciados o con resultado.'],
            ]);
        }
    }
}
