<?php

namespace Tests\Unit\Competition;

use App\Enums\CompetitionFinalStandingSource;
use App\Enums\ThirdPlaceMode;
use App\Support\Bracket\BracketSupport;
use App\Support\Competition\BracketFinalPositionsResolver;
use App\Support\Competition\CompetitionFinalMatchSnapshot;
use PHPUnit\Framework\TestCase;

class BracketFinalPositionsResolverTest extends TestCase
{
    public function test_size_two_has_only_champion_and_runner_up(): void
    {
        $placements = $this->resolve(2, ThirdPlaceMode::Shared, [
            $this->final(entry1: 1, entry2: 2, winner: 1),
        ]);

        $this->assertCount(2, $placements);
        $this->assertPlacement($placements[1], 1, 1, 1, CompetitionFinalStandingSource::Final);
        $this->assertPlacement($placements[2], 2, 2, 2, CompetitionFinalStandingSource::Final);
    }

    public function test_size_two_ignores_third_place_modes(): void
    {
        foreach ([ThirdPlaceMode::Playoff, ThirdPlaceMode::Shared, ThirdPlaceMode::None] as $mode) {
            $placements = $this->resolve(2, $mode, [
                $this->final(entry1: 10, entry2: 20, winner: 10),
            ]);

            $this->assertCount(2, $placements);
            $this->assertArrayNotHasKey(3, $this->byEntry($placements));
        }
    }

    public function test_size_four_playoff_assigns_unique_third_and_fourth(): void
    {
        $placements = $this->resolve(4, ThirdPlaceMode::Playoff, $this->sizeFourSnapshots(playoff: true));

        $this->assertCount(4, $placements);
        $this->assertPlacement($placements[1], 1, 1, 1, CompetitionFinalStandingSource::Final);
        $this->assertPlacement($placements[3], 3, 2, 2, CompetitionFinalStandingSource::Final);
        $this->assertPlacement($placements[2], 2, 3, 3, CompetitionFinalStandingSource::ThirdPlacePlayoff);
        $this->assertPlacement($placements[4], 4, 4, 4, CompetitionFinalStandingSource::ThirdPlacePlayoff);
    }

    public function test_size_four_shared_gives_both_semifinal_losers_third(): void
    {
        $placements = $this->resolve(4, ThirdPlaceMode::Shared, $this->sizeFourSnapshots(playoff: false));

        $this->assertCount(4, $placements);
        $this->assertPlacement($placements[2], 2, 3, 4, CompetitionFinalStandingSource::Semifinal);
        $this->assertPlacement($placements[4], 4, 3, 4, CompetitionFinalStandingSource::Semifinal);
        $this->assertSame(3, $placements[2]->position);
        $this->assertNotEquals(4, $placements[2]->position);
    }

    public function test_size_four_none_persists_shared_third_like_shared_mode(): void
    {
        $placements = $this->resolve(4, ThirdPlaceMode::None, $this->sizeFourSnapshots(playoff: false));

        $this->assertCount(4, $placements);
        $this->assertPlacement($placements[2], 2, 3, 4, CompetitionFinalStandingSource::Semifinal);
        $this->assertPlacement($placements[4], 4, 3, 4, CompetitionFinalStandingSource::Semifinal);
    }

    public function test_size_four_with_bye_places_real_semifinal_loser_in_geometric_third_band(): void
    {
        $placements = $this->resolve(4, ThirdPlaceMode::Shared, [
            $this->bye(1, 1, 1, 'Semifinal'),
            $this->main(1, 2, 2, 3, 2, 'Semifinal'),
            $this->final(1, 2, 1, 2),
        ]);

        $this->assertCount(3, $placements);
        $this->assertPlacement($placements[1], 1, 1, 1, CompetitionFinalStandingSource::Final);
        $this->assertPlacement($placements[2], 2, 2, 2, CompetitionFinalStandingSource::Final);
        $this->assertPlacement($placements[3], 3, 3, 4, CompetitionFinalStandingSource::Semifinal);
    }

    public function test_size_eight_quarterfinal_losers_share_five_to_eight(): void
    {
        $placements = $this->resolve(8, ThirdPlaceMode::Shared, $this->sizeEightSnapshots());

        $this->assertCount(8, $placements);

        foreach ([5, 6, 7, 8] as $entryId) {
            $this->assertPlacement($placements[$entryId], $entryId, 5, 8, CompetitionFinalStandingSource::Quarterfinal);
        }
    }

    public function test_size_eight_with_byes_keeps_geometric_quarterfinal_range(): void
    {
        $placements = $this->resolve(8, ThirdPlaceMode::Shared, $this->sizeEightWithByesSnapshots());

        $this->assertCount(5, $placements);
        $this->assertPlacement($placements[5], 5, 5, 8, CompetitionFinalStandingSource::Quarterfinal);
        $this->assertSame(5, $placements[5]->position);
        $this->assertSame(8, $placements[5]->positionRangeEnd);
        $this->assertNotSame(5, $placements[5]->positionRangeEnd);
    }

    public function test_play_in_losers_use_play_in_source_and_geometric_range(): void
    {
        $placements = $this->resolve(16, ThirdPlaceMode::Shared, $this->playInSnapshots());

        $this->assertCount(12, $placements);

        foreach ([9, 10, 11, 12] as $entryId) {
            $this->assertPlacement($placements[$entryId], $entryId, 9, 16, CompetitionFinalStandingSource::PlayIn);
        }
    }

    /**
     * @param  list<CompetitionFinalMatchSnapshot>  $snapshots
     * @return array<int, \App\Data\Competition\CompetitionFinalStandingData>
     */
    private function resolve(int $bracketSize, ThirdPlaceMode $mode, array $snapshots): array
    {
        $dtos = (new BracketFinalPositionsResolver())->resolve($bracketSize, $mode, $snapshots);

        return $this->byEntry($dtos);
    }

    /**
     * @param  list<\App\Data\Competition\CompetitionFinalStandingData>  $dtos
     * @return array<int, \App\Data\Competition\CompetitionFinalStandingData>
     */
    private function byEntry(array $dtos): array
    {
        $map = [];

        foreach ($dtos as $dto) {
            $map[$dto->competitionEntryId] = $dto;
        }

        return $map;
    }

    private function assertPlacement(
        object $dto,
        int $entryId,
        int $position,
        int $rangeEnd,
        CompetitionFinalStandingSource $source,
    ): void {
        $this->assertSame($entryId, $dto->competitionEntryId);
        $this->assertSame($position, $dto->position);
        $this->assertSame($rangeEnd, $dto->positionRangeEnd);
        $this->assertSame($source, $dto->source);
    }

    /**
     * @return list<CompetitionFinalMatchSnapshot>
     */
    private function sizeFourSnapshots(bool $playoff): array
    {
        $snapshots = [
            $this->main(1, 1, 1, 2, 1, 'Semifinal'),
            $this->main(1, 2, 3, 4, 3, 'Semifinal'),
            $this->final(1, 3, 1, 2),
        ];

        if ($playoff) {
            $snapshots[] = $this->thirdPlace(2, 4, 2);
        }

        return $snapshots;
    }

    /**
     * @return list<CompetitionFinalMatchSnapshot>
     */
    private function sizeEightSnapshots(): array
    {
        return [
            $this->main(1, 1, 1, 5, 1, 'Cuartos de final'),
            $this->main(1, 2, 2, 6, 2, 'Cuartos de final'),
            $this->main(1, 3, 3, 7, 3, 'Cuartos de final'),
            $this->main(1, 4, 4, 8, 4, 'Cuartos de final'),
            $this->main(2, 1, 1, 2, 1, 'Semifinal'),
            $this->main(2, 2, 3, 4, 3, 'Semifinal'),
            $this->final(1, 3, 1, 3),
        ];
    }

    /**
     * @return list<CompetitionFinalMatchSnapshot>
     */
    private function sizeEightWithByesSnapshots(): array
    {
        return [
            $this->bye(1, 1, 1, 'Cuartos de final'),
            $this->bye(1, 2, 2, 'Cuartos de final'),
            $this->bye(1, 3, 3, 'Cuartos de final'),
            $this->main(1, 4, 4, 5, 4, 'Cuartos de final'),
            $this->main(2, 1, 1, 2, 1, 'Semifinal'),
            $this->main(2, 2, 3, 4, 3, 'Semifinal'),
            $this->final(1, 3, 1, 3),
        ];
    }

    /**
     * @return list<CompetitionFinalMatchSnapshot>
     */
    private function playInSnapshots(): array
    {
        return [
            $this->bye(1, 1, 1, BracketSupport::PLAY_IN_ROUND_LABEL),
            $this->main(1, 2, 5, 9, 5, BracketSupport::PLAY_IN_ROUND_LABEL),
            $this->bye(1, 3, 2, BracketSupport::PLAY_IN_ROUND_LABEL),
            $this->main(1, 4, 6, 10, 6, BracketSupport::PLAY_IN_ROUND_LABEL),
            $this->bye(1, 5, 3, BracketSupport::PLAY_IN_ROUND_LABEL),
            $this->main(1, 6, 7, 11, 7, BracketSupport::PLAY_IN_ROUND_LABEL),
            $this->bye(1, 7, 4, BracketSupport::PLAY_IN_ROUND_LABEL),
            $this->main(1, 8, 8, 12, 8, BracketSupport::PLAY_IN_ROUND_LABEL),
            $this->main(2, 1, 1, 5, 1, 'Cuartos de final'),
            $this->main(2, 2, 2, 6, 2, 'Cuartos de final'),
            $this->main(2, 3, 3, 7, 3, 'Cuartos de final'),
            $this->main(2, 4, 4, 8, 4, 'Cuartos de final'),
            $this->main(3, 1, 1, 2, 1, 'Semifinal'),
            $this->main(3, 2, 3, 4, 3, 'Semifinal'),
            $this->final(1, 3, 1, 4),
        ];
    }

    private function final(int $entry1, int $entry2, int $winner, int $round = 1): CompetitionFinalMatchSnapshot
    {
        return $this->main($round, 1, $entry1, $entry2, $winner, 'Final');
    }

    private function thirdPlace(int $entry1, int $entry2, int $winner): CompetitionFinalMatchSnapshot
    {
        return new CompetitionFinalMatchSnapshot(
            bracketRound: null,
            bracketMatch: 1,
            purpose: 'third_place',
            isBye: false,
            status: 'finished',
            round: 'Tercer puesto',
            entry1Id: $entry1,
            entry2Id: $entry2,
            winnerEntryId: $winner,
        );
    }

    private function bye(int $round, int $match, int $entry1, string $label): CompetitionFinalMatchSnapshot
    {
        return new CompetitionFinalMatchSnapshot(
            bracketRound: $round,
            bracketMatch: $match,
            purpose: 'main',
            isBye: true,
            status: 'finished',
            round: $label,
            entry1Id: $entry1,
            entry2Id: null,
            winnerEntryId: $entry1,
        );
    }

    private function main(
        int $round,
        int $match,
        int $entry1,
        int $entry2,
        int $winner,
        string $label,
    ): CompetitionFinalMatchSnapshot {
        return new CompetitionFinalMatchSnapshot(
            bracketRound: $round,
            bracketMatch: $match,
            purpose: 'main',
            isBye: false,
            status: 'finished',
            round: $label,
            entry1Id: $entry1,
            entry2Id: $entry2,
            winnerEntryId: $winner,
        );
    }
}
