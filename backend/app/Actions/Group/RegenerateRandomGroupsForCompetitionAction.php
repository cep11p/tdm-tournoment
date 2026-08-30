<?php

namespace App\Actions\Group;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Enums\CompetitionEntryStatus;
use App\Enums\CompetitionType;
use App\Enums\GameStatus;
use App\Enums\TeamTieStatus;
use App\Models\Bracket;
use App\Models\Competition;
use App\Models\Game;
use App\Models\TeamTie;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use App\Support\Competition\CompetitionFormatGuard;
use App\Support\Competition\CompetitionParticipantLabel;
use App\Support\Competition\CompetitionStructureGuard;
use App\Support\Group\RandomGroupDistributionGuard;
use App\Support\Tournament\TournamentLifecycleGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RegenerateRandomGroupsForCompetitionAction
{
    public function __construct(
        private readonly BuildRandomGroupsForCompetitionAction $buildRandomGroups,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return array{
     *     groups_removed: int,
     *     games_removed: int,
     *     team_ties_removed: int,
     *     bracket_removed: bool,
     *     groups_created: int,
     *     players_assigned: int,
     *     games_created: int,
     *     team_ties_created: int,
     *     groups: \Illuminate\Support\Collection<int, \App\Models\Group>,
     * }
     */
    public function __invoke(Competition $competition, int $groupsCount): array
    {
        $competition->loadMissing('tournament');
        TournamentLifecycleGuard::ensureMutableForCompetition($competition);
        CompetitionFormatGuard::ensureGroupStage($competition);
        CompetitionStructureGuard::ensureEditable($competition);

        if (! $competition->groups()->exists()) {
            throw ValidationException::withMessages([
                'competition' => ['La competencia no tiene grupos para regenerar.'],
            ]);
        }

        $this->ensureNoNonPendingTeamTies($competition);

        $playerCount = $competition->entries()
            ->where('status', CompetitionEntryStatus::Active)
            ->count();

        if ($playerCount < 2) {
            throw ValidationException::withMessages([
                'competition' => [CompetitionParticipantLabel::minimumForGroups($competition)],
            ]);
        }

        RandomGroupDistributionGuard::ensureValid($playerCount, $groupsCount);

        return DB::transaction(function () use ($competition, $groupsCount): array {
            $oldGroupsCount = $competition->groups()->count();
            $oldGamesCount = Game::query()
                ->where('competition_id', $competition->id)
                ->count();
            $oldTeamTiesCount = TeamTie::query()
                ->where('competition_id', $competition->id)
                ->count();
            $bracketExists = $competition->brackets()->exists();

            $groupsRemoved = $oldGroupsCount;
            $gamesRemoved = 0;
            $teamTiesRemoved = 0;
            $bracketRemoved = false;

            $bracket = $competition->brackets()->first();

            if ($bracket instanceof Bracket) {
                $gamesRemoved += $this->deleteBracketGames($bracket);
                $bracket->delete();
                $bracketRemoved = true;
            }

            $gamesRemoved += Game::query()
                ->where('competition_id', $competition->id)
                ->whereNotNull('group_id')
                ->where('status', GameStatus::Pending)
                ->delete();

            $teamTiesRemoved += TeamTie::query()
                ->where('competition_id', $competition->id)
                ->where('status', TeamTieStatus::Pending)
                ->delete();

            $competition->groups()->delete();

            $buildResult = ($this->buildRandomGroups)($competition, $groupsCount);

            $result = [
                'groups_removed' => $groupsRemoved,
                'games_removed' => $gamesRemoved,
                'team_ties_removed' => $teamTiesRemoved,
                'bracket_removed' => $bracketRemoved,
                ...$buildResult,
            ];

            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::GROUPS_REGENERATED,
                logName: 'groups',
                subject: $competition,
                context: AuditContextBuilder::fromCompetition($competition),
                old: [
                    'groups_count' => $oldGroupsCount,
                    'games_count' => $oldGamesCount,
                    'team_ties_count' => $oldTeamTiesCount,
                    'bracket_exists' => $bracketExists,
                ],
                new: [
                    'groups_count' => $buildResult['groups_created'],
                    'games_count' => $buildResult['games_created'],
                    'team_ties_count' => $buildResult['team_ties_created'],
                ],
                summary: [
                    'groups_removed' => $groupsRemoved,
                    'games_removed' => $gamesRemoved,
                    'team_ties_removed' => $teamTiesRemoved,
                    'bracket_removed' => $bracketRemoved,
                    'groups_created' => $buildResult['groups_created'],
                    'players_assigned' => $buildResult['players_assigned'],
                    'games_created' => $buildResult['games_created'],
                    'team_ties_created' => $buildResult['team_ties_created'],
                ],
            ));

            return $result;
        });
    }

    private function ensureNoNonPendingTeamTies(Competition $competition): void
    {
        $type = $competition->type instanceof CompetitionType
            ? $competition->type
            : CompetitionType::from((string) $competition->type);

        if ($type !== CompetitionType::Team) {
            return;
        }

        $hasNonPendingTeamTies = TeamTie::query()
            ->where('competition_id', $competition->id)
            ->where('status', '!=', TeamTieStatus::Pending)
            ->exists();

        if ($hasNonPendingTeamTies) {
            throw ValidationException::withMessages([
                'competition' => ['No se pueden regenerar los grupos porque ya hay enfrentamientos iniciados o finalizados.'],
            ]);
        }
    }

    private function deleteBracketGames(Bracket $bracket): int
    {
        return Game::query()
            ->where('bracket_id', $bracket->id)
            ->where(function ($query): void {
                $query->where('status', GameStatus::Pending)
                    ->orWhere('is_bye', true);
            })
            ->delete();
    }
}
