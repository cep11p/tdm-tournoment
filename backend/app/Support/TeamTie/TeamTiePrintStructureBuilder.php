<?php

namespace App\Support\TeamTie;

use App\Data\TeamTie\PrintTeamTieData;
use App\Enums\CompetitionType;
use App\Enums\GameStatus;
use App\Enums\TeamTieGameSide;
use App\Enums\TeamTieModality;
use App\Enums\TeamTieStatus;
use App\Models\CompetitionEntry;
use App\Models\Player;
use App\Models\TeamTie;
use App\Models\TeamTieGame;
use App\Models\TeamTieGameMember;
use App\Support\Competition\CompetitionEntryDisplayName;

final class TeamTiePrintStructureBuilder
{
    public function build(TeamTie $teamTie): PrintTeamTieData
    {
        $teamTie->loadMissing([
            'competition.tournament',
            'group',
            'entry1',
            'entry2',
            'winnerEntry',
            'teamTieGames' => fn ($query) => $query->orderBy('slot_order'),
            'teamTieGames.game',
            'teamTieGames.members.competitionEntryMember.player',
        ]);

        $side1 = $this->side($teamTie->entry1);
        $isBye = (bool) $teamTie->is_bye;

        if ($isBye) {
            return $this->sheet(
                teamTie: $teamTie,
                side1: $side1,
                side2: null,
                score: ['side1' => 0, 'side2' => 0],
                winner: $side1,
                rubbers: [],
            );
        }

        $score = TeamTieScoreResolver::resolve($teamTie);
        $officialSlotOrders = [];

        foreach (TeamTieOutcomeResolver::officialRubbers($teamTie) as $officialRubber) {
            $officialSlotOrders[(int) $officialRubber['slot_order']] = true;
        }

        $orderedRubbers = $teamTie->teamTieGames
            ->sortBy(fn (TeamTieGame $rubber): int => (int) $rubber->slot_order)
            ->values();

        $labels = TeamTiePrintSlotLabel::forModalities(
            $orderedRubbers
                ->map(fn (TeamTieGame $rubber): TeamTieModality => $this->modality($rubber))
                ->all(),
        );

        $rubbers = [];

        foreach ($orderedRubbers as $index => $rubber) {
            $slotOrder = (int) $rubber->slot_order;
            $game = $rubber->game;
            $status = $this->gameStatus($game?->status);
            $winnerSide = $this->winnerSide($teamTie, $game?->winner_entry_id);

            $rubbers[] = [
                'slot_order' => $slotOrder,
                'type' => $this->modality($rubber)->value,
                'label' => $labels[$index] ?? '',
                'status' => $status,
                'official' => isset($officialSlotOrders[$slotOrder]),
                'lineup_complete' => $rubber->isLineupComplete(),
                'side1' => [
                    'players' => $this->playersForSide($rubber, TeamTieGameSide::Entry1),
                ],
                'side2' => [
                    'players' => $this->playersForSide($rubber, TeamTieGameSide::Entry2),
                ],
                'winner_side' => $winnerSide,
            ];
        }

        return $this->sheet(
            teamTie: $teamTie,
            side1: $side1,
            side2: $this->side($teamTie->entry2),
            score: [
                'side1' => (int) $score['entry1'],
                'side2' => (int) $score['entry2'],
            ],
            winner: $this->winnerPayload($teamTie),
            rubbers: $rubbers,
        );
    }

    /**
     * @param  array{competition_entry_id: int, display_name: string}|null  $side1
     * @param  array{competition_entry_id: int, display_name: string}|null  $side2
     * @param  array{side1: int, side2: int}  $score
     * @param  array{competition_entry_id: int, display_name: string}|null  $winner
     * @param  list<array<string, mixed>>  $rubbers
     */
    private function sheet(
        TeamTie $teamTie,
        ?array $side1,
        ?array $side2,
        array $score,
        ?array $winner,
        array $rubbers,
    ): PrintTeamTieData {
        $competition = $teamTie->competition;
        $type = $competition?->type instanceof CompetitionType
            ? $competition->type
            : CompetitionType::from((string) ($competition?->type ?? CompetitionType::Team->value));
        $status = $teamTie->status instanceof TeamTieStatus
            ? $teamTie->status
            : TeamTieStatus::from((string) $teamTie->status);

        return new PrintTeamTieData(
            tournament: [
                'id' => (int) ($competition?->tournament_id ?? 0),
                'name' => (string) ($competition?->tournament?->name ?? ''),
            ],
            competition: [
                'id' => (int) $teamTie->competition_id,
                'name' => (string) ($competition?->name ?? ''),
                'type' => $type->value,
            ],
            teamTie: [
                'id' => (int) $teamTie->id,
                'context_label' => $this->contextLabel($teamTie),
                'status' => $status->value,
                'is_bye' => (bool) $teamTie->is_bye,
                'victories_required' => (int) $teamTie->victories_required,
            ],
            side1: $side1,
            side2: $side2,
            score: $score,
            winner: $winner,
            format: [
                'name' => (string) $teamTie->format_name,
                'slots_count' => count($rubbers),
            ],
            rubbers: $rubbers,
        );
    }

    private function contextLabel(TeamTie $teamTie): string
    {
        if ($teamTie->group_id !== null) {
            $groupName = trim((string) ($teamTie->group?->name ?? ''));

            if ($teamTie->group_round !== null) {
                $roundLabel = sprintf('Ronda %d', (int) $teamTie->group_round);

                return $groupName === '' ? $roundLabel : $groupName.' · '.$roundLabel;
            }

            return $groupName;
        }

        return trim((string) ($teamTie->round ?? ''));
    }

    /**
     * @return array{competition_entry_id: int, display_name: string}|null
     */
    private function winnerPayload(TeamTie $teamTie): ?array
    {
        $status = $teamTie->status instanceof TeamTieStatus
            ? $teamTie->status
            : TeamTieStatus::from((string) $teamTie->status);

        if ($status !== TeamTieStatus::Finished || $teamTie->winner_entry_id === null) {
            return null;
        }

        $winnerEntryId = (int) $teamTie->winner_entry_id;

        if ((int) $teamTie->entry1_id === $winnerEntryId) {
            return $this->side($teamTie->entry1);
        }

        if ((int) $teamTie->entry2_id === $winnerEntryId) {
            return $this->side($teamTie->entry2);
        }

        return $this->side($teamTie->winnerEntry);
    }

    /**
     * @return array{competition_entry_id: int, display_name: string}|null
     */
    private function side(?CompetitionEntry $entry): ?array
    {
        if ($entry === null) {
            return null;
        }

        return [
            'competition_entry_id' => (int) $entry->id,
            'display_name' => CompetitionEntryDisplayName::for($entry),
        ];
    }

    /**
     * @return list<array{id: int|null, name: string}>
     */
    private function playersForSide(TeamTieGame $rubber, TeamTieGameSide $side): array
    {
        return $rubber->members
            ->filter(fn (TeamTieGameMember $member): bool => $member->side === $side)
            ->sortBy(fn (TeamTieGameMember $member): int => (int) $member->player_order)
            ->values()
            ->map(function (TeamTieGameMember $member): array {
                $player = $member->competitionEntryMember?->player;

                return [
                    'id' => $player?->id !== null ? (int) $player->id : null,
                    'name' => $this->playerName($player),
                ];
            })
            ->all();
    }

    private function playerName(?Player $player): string
    {
        if ($player === null) {
            return '';
        }

        return trim(sprintf('%s %s', (string) $player->first_name, (string) $player->last_name));
    }

    private function modality(TeamTieGame $rubber): TeamTieModality
    {
        return $rubber->modality instanceof TeamTieModality
            ? $rubber->modality
            : TeamTieModality::from((string) $rubber->modality);
    }

    private function gameStatus(null|GameStatus|string $status): string
    {
        if ($status instanceof GameStatus) {
            return $status->value;
        }

        if (is_string($status) && $status !== '') {
            return $status;
        }

        return GameStatus::Pending->value;
    }

    private function winnerSide(TeamTie $teamTie, mixed $winnerEntryId): ?int
    {
        if ($winnerEntryId === null) {
            return null;
        }

        $winnerId = (int) $winnerEntryId;

        if ((int) $teamTie->entry1_id === $winnerId) {
            return 1;
        }

        if ($teamTie->entry2_id !== null && (int) $teamTie->entry2_id === $winnerId) {
            return 2;
        }

        return null;
    }
}
