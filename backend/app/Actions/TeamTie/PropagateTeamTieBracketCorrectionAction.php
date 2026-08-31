<?php

namespace App\Actions\TeamTie;

use App\Models\TeamTie;
use App\Support\Bracket\BracketPodiumSupport;
use App\Support\TeamTie\TeamTieBracketCorrectionGuard;
use App\Support\TeamTie\TeamTieDependencyResolver;

final class PropagateTeamTieBracketCorrectionAction
{
    public function __construct(
        private readonly TeamTieDependencyResolver $dependencyResolver,
        private readonly TeamTieBracketCorrectionGuard $correctionGuard,
        private readonly RematerializeTeamTieRubbersAction $rematerializeTeamTieRubbers,
    ) {}

    public function __invoke(
        TeamTie $source,
        int $previousWinnerEntryId,
        int $newWinnerEntryId,
    ): void {
        if ($source->bracket_id === null) {
            return;
        }

        if ($previousWinnerEntryId === $newWinnerEntryId) {
            return;
        }

        $this->correctionGuard->assertSourceCorrectable($source);
        $this->correctionGuard->assertNoRoundBeyondImmediate($source);

        $previousLoserEntryId = $this->loserEntryIdForWinner($source, $previousWinnerEntryId);
        $newLoserEntryId = $this->loserEntryIdForWinner($source, $newWinnerEntryId);

        $winnerDependency = $this->dependencyResolver->resolveWinnerDependency($source);
        $loserDependency = $this->dependencyResolver->resolveLoserThirdPlaceDependency($source);

        $destinationIds = [];

        if ($winnerDependency !== null) {
            $destinationIds[] = (int) $winnerDependency['team_tie']->id;
        }

        if ($loserDependency !== null) {
            $destinationIds[] = (int) $loserDependency['team_tie']->id;
        }

        $destinationIds = array_values(array_unique($destinationIds));
        sort($destinationIds);

        /** @var array<int, TeamTie> $lockedDestinations */
        $lockedDestinations = [];

        foreach ($destinationIds as $destinationId) {
            $lockedDestinations[$destinationId] = TeamTie::query()
                ->lockForUpdate()
                ->findOrFail($destinationId);
        }

        $propagations = [];

        if ($winnerDependency !== null) {
            $destination = $lockedDestinations[(int) $winnerDependency['team_tie']->id];

            $propagations[] = [
                'destination' => $destination,
                'slot' => $winnerDependency['slot'],
                'oldParticipantId' => $previousWinnerEntryId,
                'newParticipantId' => $newWinnerEntryId,
                'context' => $this->winnerPropagationContext($source, $winnerDependency),
            ];
        }

        if ($loserDependency !== null) {
            $destination = $lockedDestinations[(int) $loserDependency['team_tie']->id];

            $propagations[] = [
                'destination' => $destination,
                'slot' => $loserDependency['slot'],
                'oldParticipantId' => $previousLoserEntryId,
                'newParticipantId' => $newLoserEntryId,
                'context' => 'third_place',
            ];
        }

        $this->correctionGuard->assertPropagationsSafe($propagations);

        foreach ($propagations as $propagation) {
            if ((int) $propagation['oldParticipantId'] === (int) $propagation['newParticipantId']) {
                continue;
            }

            $destination = $propagation['destination'];
            $destination->{$propagation['slot']} = (int) $propagation['newParticipantId'];
            $destination->save();

            ($this->rematerializeTeamTieRubbers)($destination->fresh());
        }
    }

    /**
     * @param  array{
     *     team_tie: TeamTie,
     *     slot: 'entry1_id'|'entry2_id',
     *     destination_round: int,
     *     destination_match: int,
     *     expected_entry_id: int,
     * }  $winnerDependency
     * @return 'final'|'next_round'
     */
    private function winnerPropagationContext(TeamTie $source, array $winnerDependency): string
    {
        $source->loadMissing('bracket');
        $bracket = $source->bracket;

        if ($bracket === null) {
            return 'next_round';
        }

        $finalRound = BracketPodiumSupport::finalRound($bracket);

        if ((int) $winnerDependency['destination_round'] === $finalRound) {
            return 'final';
        }

        return 'next_round';
    }

    private function loserEntryIdForWinner(TeamTie $teamTie, int $winnerEntryId): int
    {
        return (int) $teamTie->entry1_id === $winnerEntryId
            ? (int) $teamTie->entry2_id
            : (int) $teamTie->entry1_id;
    }
}
