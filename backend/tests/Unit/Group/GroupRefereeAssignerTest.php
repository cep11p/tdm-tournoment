<?php

namespace Tests\Unit\Group;

use App\Enums\GameStatus;
use App\Models\CompetitionEntry;
use App\Models\Game;
use App\Support\Group\GroupFixtureOrder;
use App\Support\Group\GroupRefereeAssigner;
use App\Support\Group\GroupSheetNumbering;
use Illuminate\Support\Collection;
use Tests\TestCase;

class GroupRefereeAssignerTest extends TestCase
{
    private GroupRefereeAssigner $assigner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders($this->authHeaders(['organizer']));
        $this->assigner = app(GroupRefereeAssigner::class);
    }

    public function test_group_of_two_returns_null_referee_for_the_only_game(): void
    {
        $games = $this->generateOrderedFixture(playerCount: 2);

        $this->assertCount(1, $games);

        $assignment = $this->assigner->assign($games);

        $this->assertCount(1, $assignment);
        $this->assertNull($assignment[(int) $games->first()->id]);
    }

    public function test_group_of_three_assigns_each_entry_exactly_once_and_never_a_side(): void
    {
        $games = $this->generateOrderedFixture(playerCount: 3);
        $assignment = $this->assigner->assign($games);

        $this->assertCount(3, $games);
        $this->assertEveryGameHasRefereeNotPlaying($games, $assignment);

        $counts = array_count_values(array_values($assignment));
        $this->assertCount(3, $counts);
        $this->assertTrue(collect($counts)->every(fn (int $count): bool => $count === 1));
    }

    public function test_group_of_four_fixture_follows_official_playing_order(): void
    {
        $games = $this->generateOrderedFixture(playerCount: 4);
        $entryIds = $games
            ->flatMap(fn (Game $game): array => [(int) $game->entry1_id, (int) $game->entry2_id])
            ->unique()
            ->values()
            ->all();
        $numbering = GroupSheetNumbering::forCompetitionEntryIds($entryIds);

        $this->assertSame(
            [
                [1, 3],
                [2, 4],
                [1, 2],
                [3, 4],
                [1, 4],
                [2, 3],
            ],
            $games->map(fn (Game $game): array => [
                $numbering[(int) $game->entry1_id],
                $numbering[(int) $game->entry2_id],
            ])->all(),
        );
    }

    public function test_group_of_four_never_assigns_a_side_keeps_equity_and_avoids_consecutive(): void
    {
        $games = $this->generateOrderedFixture(playerCount: 4);
        $assignment = $this->assigner->assign($games);

        $this->assertCount(6, $games);
        $this->assertEveryGameHasRefereeNotPlaying($games, $assignment);
        $this->assertRefereeCountsDifferByAtMostOne($assignment);

        $previous = null;

        foreach ($games as $game) {
            $refereeId = $assignment[(int) $game->id];
            $this->assertNotNull($refereeId);

            if ($previous !== null) {
                $this->assertNotSame(
                    $previous,
                    $refereeId,
                    'El fixture de 4 no debería repetir árbitro en partidos consecutivos.',
                );
            }

            $previous = $refereeId;
        }
    }

    public function test_group_of_five_never_assigns_a_side_and_keeps_equity(): void
    {
        $games = $this->generateOrderedFixture(playerCount: 5);
        $assignment = $this->assigner->assign($games);

        $this->assertCount(10, $games);
        $this->assertEveryGameHasRefereeNotPlaying($games, $assignment);
        $this->assertRefereeCountsDifferByAtMostOne($assignment);
    }

    public function test_group_of_six_never_assigns_a_side_and_keeps_equity(): void
    {
        $games = $this->generateOrderedFixture(playerCount: 6);
        $assignment = $this->assigner->assign($games);

        $this->assertCount(15, $games);
        $this->assertEveryGameHasRefereeNotPlaying($games, $assignment);
        $this->assertRefereeCountsDifferByAtMostOne($assignment);
    }

    public function test_assignment_is_deterministic(): void
    {
        $games = $this->generateOrderedFixture(playerCount: 5);

        $first = $this->assigner->assign($games);
        $second = $this->assigner->assign($games);

        $this->assertSame($first, $second);
    }

    public function test_assignment_does_not_depend_on_game_status(): void
    {
        $games = $this->generateOrderedFixture(playerCount: 4);
        $before = $this->assigner->assign($games);

        foreach ($games as $game) {
            $game->update(['status' => GameStatus::Finished]);
        }

        $after = $this->assigner->assign(GroupFixtureOrder::games($games->first()->group));

        $this->assertSame($before, $after);
    }

    public function test_doubles_referee_is_a_competition_entry_not_a_player(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition();
        $players = $context->createPlayers(6);
        $entries = $context->registerPairs($competition, [
            [$players[0], $players[1]],
            [$players[2], $players[3]],
            [$players[4], $players[5]],
        ]);
        $group = $context->createGroup($competition);

        foreach ($entries as $entry) {
            $context->assignEntryToGroupViaApi($group, $entry)->assertCreated();
        }

        $context->generateRoundRobin($group)->assertCreated();

        $games = GroupFixtureOrder::games($group->fresh());
        $assignment = $this->assigner->assign($games);
        $entryIds = collect($entries)->map(fn ($entry): int => (int) $entry->id)->all();

        $this->assertCount(3, $games);
        $this->assertEveryGameHasRefereeNotPlaying($games, $assignment);

        foreach ($assignment as $refereeId) {
            $this->assertContains($refereeId, $entryIds);
            $referee = CompetitionEntry::query()->with('members')->find($refereeId);
            $this->assertNotNull($referee);
            $this->assertCount(2, $referee->members);
        }
    }

    /**
     * @return Collection<int, Game>
     */
    private function generateOrderedFixture(int $playerCount): Collection
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers($playerCount);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);
        $context->generateRoundRobin($group)->assertCreated();

        return GroupFixtureOrder::games($group->fresh());
    }

    /**
     * @param  Collection<int, Game>  $games
     * @param  array<int, int|null>  $assignment
     */
    private function assertEveryGameHasRefereeNotPlaying(Collection $games, array $assignment): void
    {
        foreach ($games as $game) {
            $refereeId = $assignment[(int) $game->id] ?? null;
            $this->assertNotNull($refereeId, "El partido {$game->id} debería tener árbitro.");
            $this->assertNotSame((int) $game->entry1_id, $refereeId);
            $this->assertNotSame((int) $game->entry2_id, $refereeId);
        }
    }

    /**
     * @param  array<int, int|null>  $assignment
     */
    private function assertRefereeCountsDifferByAtMostOne(array $assignment): void
    {
        $counts = array_count_values(array_filter($assignment, fn ($id): bool => $id !== null));

        $this->assertNotEmpty($counts);
        $this->assertLessThanOrEqual(1, max($counts) - min($counts));
    }
}
