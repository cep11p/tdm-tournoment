<?php

namespace App\Actions\Group;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Models\Game;
use App\Models\Group;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use App\Support\Competition\CompetitionFormatGuard;
use App\Support\Tournament\TournamentLifecycleGuard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class GenerateGroupRoundRobinGamesAction
{
    public function __construct(
        private readonly BuildGroupRoundRobinGamesAction $buildRoundRobin,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return Collection<int, Game>
     */
    public function __invoke(Group $group): Collection
    {
        $group->loadMissing('competition.tournament');
        TournamentLifecycleGuard::ensureMutableForGroup($group);
        CompetitionFormatGuard::ensureGroupStage($group->competition);

        $playerIds = $group->groupPlayers()
            ->orderBy('player_id')
            ->pluck('player_id')
            ->map(fn ($playerId) => (int) $playerId)
            ->values()
            ->all();

        if (count($playerIds) < 2) {
            throw ValidationException::withMessages([
                'group' => ['El grupo necesita al menos 2 jugadores.'],
            ]);
        }

        if ($group->games()->exists()) {
            throw ValidationException::withMessages([
                'group' => ['Los partidos del round robin ya fueron generados para este grupo.'],
            ]);
        }

        $playerCount = count($playerIds);

        return DB::transaction(function () use ($group, $playerCount): Collection {
            $created = ($this->buildRoundRobin)($group);
            $gamesCreated = $created->count();

            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::GROUPS_ROUND_ROBIN_GENERATED,
                logName: 'groups',
                subject: $group,
                context: AuditContextBuilder::fromGroup($group),
                new: [
                    'games_count' => $gamesCreated,
                ],
                summary: [
                    'player_count' => $playerCount,
                    'games_created' => $gamesCreated,
                    'existing_games_before' => 0,
                ],
            ));

            return $created;
        });
    }
}
