<?php

namespace App\Actions\TeamTie;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Enums\GameStatus;
use App\Enums\TeamTieStatus;
use App\Models\TeamTie;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use App\Support\Competition\CompetitionEntryDisplayName;
use App\Support\TeamTie\TeamTieOutcomeResolver;

final class RecalculateTeamTieOutcomeAction
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function __invoke(TeamTie|int $teamTie): TeamTie
    {
        $teamTieId = $teamTie instanceof TeamTie ? (int) $teamTie->id : $teamTie;

        $teamTie = TeamTie::query()
            ->with([
                'competition.tournament',
                'entry1',
                'entry2',
                'winnerEntry',
                'teamTieGames' => fn ($query) => $query->orderBy('slot_order'),
                'teamTieGames.game.sets',
            ])
            ->lockForUpdate()
            ->findOrFail($teamTieId);

        $outcome = TeamTieOutcomeResolver::resolve($teamTie);

        $previousStatus = $teamTie->status instanceof TeamTieStatus
            ? $teamTie->status
            : TeamTieStatus::from((string) $teamTie->status);
        $previousWinnerEntryId = $teamTie->winner_entry_id !== null
            ? (int) $teamTie->winner_entry_id
            : null;

        $this->syncRubberStatuses($teamTie, $outcome);

        $hasActivity = $this->teamTieHasActivity($teamTie);

        if ($outcome['is_decided']) {
            $teamTie->status = TeamTieStatus::Finished;
            $teamTie->winner_entry_id = $outcome['winner_entry_id'];

            if ($teamTie->finished_at === null) {
                $teamTie->finished_at = now();
            }
        } elseif ($hasActivity) {
            $teamTie->status = TeamTieStatus::InProgress;
            $teamTie->winner_entry_id = null;
            $teamTie->finished_at = null;
        } else {
            $teamTie->status = TeamTieStatus::Pending;
            $teamTie->winner_entry_id = null;
            $teamTie->finished_at = null;
        }

        $teamTie->save();

        $this->auditTransitions(
            teamTie: $teamTie->fresh(['entry1', 'entry2', 'winnerEntry']),
            outcome: $outcome,
            previousStatus: $previousStatus,
            previousWinnerEntryId: $previousWinnerEntryId,
            reopenedSlots: $outcome['slots_to_reopen'],
        );

        return $teamTie->fresh([
            'entry1',
            'entry2',
            'winnerEntry',
            'teamTieGames.game.sets',
        ]);
    }

    /**
     * @param  array{
     *     entry1_wins: int,
     *     entry2_wins: int,
     *     victories_required: int,
     *     winner_entry_id: int|null,
     *     is_decided: bool,
     *     clinch_slot_order: int|null,
     *     rubbers_counting: int,
     *     rubbers_finished_total: int,
     *     rubbers_total: int,
     *     slots_to_mark_not_needed: list<int>,
     *     slots_to_reopen: list<int>,
     * }  $outcome
     */
    private function syncRubberStatuses(TeamTie $teamTie, array $outcome): void
    {
        $slotsToMark = array_flip($outcome['slots_to_mark_not_needed']);
        $slotsToReopen = array_flip($outcome['slots_to_reopen']);

        foreach ($teamTie->teamTieGames as $teamTieGame) {
            $game = $teamTieGame->game;

            if ($game === null) {
                continue;
            }

            $slotOrder = (int) $teamTieGame->slot_order;

            if (isset($slotsToMark[$slotOrder]) && $game->status === GameStatus::Pending) {
                $game->status = GameStatus::NotNeeded;
                $game->save();

                continue;
            }

            if (isset($slotsToReopen[$slotOrder]) && $game->status === GameStatus::NotNeeded) {
                $game->status = GameStatus::Pending;
                $game->save();
            }
        }
    }

    private function teamTieHasActivity(TeamTie $teamTie): bool
    {
        foreach ($teamTie->teamTieGames as $teamTieGame) {
            $game = $teamTieGame->game;

            if ($game === null) {
                continue;
            }

            if ($game->status === GameStatus::InProgress || $game->status === GameStatus::Finished) {
                return true;
            }

            if ($game->relationLoaded('sets') ? $game->sets->isNotEmpty() : $game->sets()->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{
     *     entry1_wins: int,
     *     entry2_wins: int,
     *     victories_required: int,
     *     winner_entry_id: int|null,
     *     is_decided: bool,
     *     clinch_slot_order: int|null,
     *     rubbers_counting: int,
     *     rubbers_finished_total: int,
     *     rubbers_total: int,
     *     slots_to_mark_not_needed: list<int>,
     *     slots_to_reopen: list<int>,
     * }  $outcome
     * @param  list<int>  $reopenedSlots
     */
    private function auditTransitions(
        TeamTie $teamTie,
        array $outcome,
        TeamTieStatus $previousStatus,
        ?int $previousWinnerEntryId,
        array $reopenedSlots,
    ): void {
        $newStatus = $teamTie->status instanceof TeamTieStatus
            ? $teamTie->status
            : TeamTieStatus::from((string) $teamTie->status);
        $newWinnerEntryId = $teamTie->winner_entry_id !== null
            ? (int) $teamTie->winner_entry_id
            : null;

        $officialScore = [
            'entry1' => $outcome['entry1_wins'],
            'entry2' => $outcome['entry2_wins'],
        ];

        $context = AuditContextBuilder::fromTeamTie($teamTie);

        if (
            $newStatus === TeamTieStatus::Finished
            && $previousStatus !== TeamTieStatus::Finished
        ) {
            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::TEAM_TIE_FINISHED,
                logName: 'team_ties',
                subject: $teamTie,
                context: $context,
                old: [
                    'status' => $previousStatus->value,
                    'winner_entry_id' => $previousWinnerEntryId,
                ],
                new: [
                    'status' => $newStatus->value,
                    'winner_entry_id' => $newWinnerEntryId,
                    'finished_at' => $teamTie->finished_at?->toIso8601String(),
                ],
                summary: array_merge($context, [
                    'official_score' => $officialScore,
                    'victories_required' => $outcome['victories_required'],
                    'clinch_slot_order' => $outcome['clinch_slot_order'],
                ]),
            ));

            return;
        }

        if (
            $previousStatus === TeamTieStatus::Finished
            && $newStatus === TeamTieStatus::InProgress
        ) {
            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::TEAM_TIE_REOPENED,
                logName: 'team_ties',
                subject: $teamTie,
                context: $context,
                old: [
                    'status' => $previousStatus->value,
                    'winner_entry_id' => $previousWinnerEntryId,
                ],
                new: [
                    'status' => $newStatus->value,
                    'winner_entry_id' => null,
                    'finished_at' => null,
                ],
                summary: array_merge($context, [
                    'previous_winner_entry_id' => $previousWinnerEntryId,
                    'previous_winner_display_name' => $this->winnerDisplayName($teamTie, $previousWinnerEntryId),
                    'official_score' => $officialScore,
                    'rubbers_reopened' => $reopenedSlots,
                ]),
            ));

            return;
        }

        if (
            $previousStatus === TeamTieStatus::Finished
            && $newStatus === TeamTieStatus::Finished
            && $previousWinnerEntryId !== null
            && $newWinnerEntryId !== null
            && $previousWinnerEntryId !== $newWinnerEntryId
        ) {
            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::TEAM_TIE_RESULT_CHANGED,
                logName: 'team_ties',
                subject: $teamTie,
                context: $context,
                old: [
                    'winner_entry_id' => $previousWinnerEntryId,
                    'winner_display_name' => $this->winnerDisplayName($teamTie, $previousWinnerEntryId),
                    'official_score' => $officialScore,
                ],
                new: [
                    'winner_entry_id' => $newWinnerEntryId,
                    'winner_display_name' => $teamTie->winnerEntry !== null
                        ? CompetitionEntryDisplayName::for($teamTie->winnerEntry)
                        : null,
                    'official_score' => $officialScore,
                ],
                summary: array_merge($context, [
                    'previous_winner_entry_id' => $previousWinnerEntryId,
                    'previous_winner_display_name' => $this->winnerDisplayName($teamTie, $previousWinnerEntryId),
                    'new_winner_entry_id' => $newWinnerEntryId,
                    'new_winner_display_name' => $teamTie->winnerEntry !== null
                        ? CompetitionEntryDisplayName::for($teamTie->winnerEntry)
                        : null,
                    'official_score' => $officialScore,
                    'victories_required' => $outcome['victories_required'],
                ]),
            ));
        }
    }

    private function winnerDisplayName(TeamTie $teamTie, ?int $winnerEntryId): ?string
    {
        if ($winnerEntryId === null) {
            return null;
        }

        if ((int) $teamTie->entry1_id === $winnerEntryId) {
            return CompetitionEntryDisplayName::for($teamTie->entry1);
        }

        if ((int) $teamTie->entry2_id === $winnerEntryId) {
            return CompetitionEntryDisplayName::for($teamTie->entry2);
        }

        return null;
    }
}
