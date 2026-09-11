<?php

namespace Tests\Feature\Group;

use App\Enums\CompetitionEntryStatus;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\CompetitionEntryMember;
use App\Models\Game;
use App\Models\Group;
use App\Models\Player;
use App\Support\Group\GroupSheetNumbering;
use App\Support\Group\RoundRobinScheduleBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GroupRoundRobinScheduleTest extends TestCase
{
    public function test_even_group_generates_correct_game_count(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);

        $context->generateRoundRobin($group)->assertCreated();

        $games = Game::query()->where('group_id', $group->id)->get();

        $this->assertSame(6, $games->count());
        $this->assertTrue($games->every(
            fn (Game $game): bool => $game->entry1_id !== null && $game->entry2_id !== null && $game->entry1_id !== $game->entry2_id,
        ));
    }

    public function test_odd_group_generates_correct_game_count_without_bye_games(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(5);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);

        $context->generateRoundRobin($group)->assertCreated();

        $games = Game::query()->where('group_id', $group->id)->get();

        $this->assertSame(10, $games->count());
        $this->assertTrue($games->every(fn (Game $game): bool => ! $game->is_bye));
    }

    public function test_no_player_appears_more_than_once_in_the_same_round(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(5);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);

        $context->generateRoundRobin($group)->assertCreated();

        $games = Game::query()
            ->where('group_id', $group->id)
            ->orderBy('group_round')
            ->orderBy('group_match')
            ->get();

        $gamesByRound = $games->groupBy('group_round');

        foreach ($gamesByRound as $roundGames) {
            $playersInRound = [];

            foreach ($roundGames as $game) {
                $playersInRound[] = (int) $game->singlesPlayer1Id();
                $playersInRound[] = (int) $game->singlesPlayer2Id();
            }

            $this->assertSame(
                count($playersInRound),
                count(array_unique($playersInRound)),
                'Un jugador apareció más de una vez en la misma ronda.',
            );
        }
    }

    public function test_generated_games_have_group_round_and_group_match(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);

        $response = $context->generateRoundRobin($group)->assertCreated();

        $response->assertJsonPath('data.0.group_round', 1);
        $response->assertJsonPath('data.0.group_match', 1);

        $games = Game::query()->where('group_id', $group->id)->get();

        $this->assertTrue($games->every(
            fn (Game $game): bool => $game->group_round !== null && $game->group_match !== null,
        ));
    }

    public function test_round_robin_does_not_duplicate_pairings(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(6);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);

        $context->generateRoundRobin($group)->assertCreated();

        $games = Game::query()->where('group_id', $group->id)->get();
        $pairings = $games->map(function (Game $game): string {
            $playerIds = [(int) $game->singlesPlayer1Id(), (int) $game->singlesPlayer2Id()];
            sort($playerIds);

            return implode('-', $playerIds);
        });

        $this->assertSame($pairings->count(), $pairings->unique()->count());
        $this->assertSame(15, $pairings->count());
    }

    public function test_round_robin_response_is_ordered_by_group_round_and_group_match(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);

        $response = $context->generateRoundRobin($group)->assertCreated();
        $payload = collect($response->json('data'));

        $sortedPayload = $payload
            ->sortBy([
                ['group_round', 'asc'],
                ['group_match', 'asc'],
                ['id', 'asc'],
            ])
            ->values();

        $this->assertSame(
            $sortedPayload->pluck('id')->all(),
            $payload->pluck('id')->all(),
        );
    }

    public function test_g3_persists_official_playing_order(): void
    {
        $group = $this->generateSinglesGroup(3);

        $this->assertOfficialSheetSlots($group, [
            [1, 1, 1, 3],
            [2, 1, 1, 2],
            [3, 1, 2, 3],
        ]);
    }

    public function test_g4_persists_official_playing_order(): void
    {
        $group = $this->generateSinglesGroup(4);

        $this->assertOfficialSheetSlots($group, [
            [1, 1, 1, 3],
            [1, 2, 2, 4],
            [2, 1, 1, 2],
            [2, 2, 3, 4],
            [3, 1, 1, 4],
            [3, 2, 2, 3],
        ]);
    }

    public function test_g5_persists_official_playing_order_and_orientation(): void
    {
        $group = $this->generateSinglesGroup(5);
        $slots = $this->persistedSheetSlots($group);

        $this->assertSame(
            [
                [1, 1, 2, 5],
                [1, 2, 3, 4],
                [2, 1, 1, 5],
                [2, 2, 2, 3],
                [3, 1, 1, 4],
                [3, 2, 5, 3],
                [4, 1, 1, 3],
                [4, 2, 4, 2],
                [5, 1, 1, 2],
                [5, 2, 4, 5],
            ],
            $slots,
        );
        $this->assertSame([5, 3], [$slots[5][2], $slots[5][3]]);
        $this->assertSame([4, 2], [$slots[7][2], $slots[7][3]]);
        $this->assertNotSame([3, 5], [$slots[5][2], $slots[5][3]]);
        $this->assertNotSame([2, 4], [$slots[7][2], $slots[7][3]]);
        $this->assertEachUnorderedPairOnce($slots, 5);
    }

    public function test_g4_numbering_follows_sorted_non_contiguous_competition_entry_ids(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(4);
        $ids = [90, 12, 35, 28];
        $entries = [];

        foreach ($players as $index => $player) {
            $entries[] = $this->createSinglesEntryWithId($competition, $player, $ids[$index]);
        }

        $group = $context->createGroupWithEntries($competition, $entries);
        $context->generateRoundRobin($group)->assertCreated();

        $this->assertSame(
            [
                12 => 1,
                28 => 2,
                35 => 3,
                90 => 4,
            ],
            GroupSheetNumbering::forCompetitionEntryIds(
                $group->groupEntries()->pluck('competition_entry_id')->map(fn ($id): int => (int) $id)->all(),
            ),
        );
        $this->assertOfficialSheetSlots($group, [
            [1, 1, 1, 3],
            [1, 2, 2, 4],
            [2, 1, 1, 2],
            [2, 2, 3, 4],
            [3, 1, 1, 4],
            [3, 2, 2, 3],
        ]);
        $this->assertSame(
            [
                [1, 1, 12, 35],
                [1, 2, 28, 90],
                [2, 1, 12, 28],
                [2, 2, 35, 90],
                [3, 1, 12, 90],
                [3, 2, 28, 35],
            ],
            $this->persistedEntrySlots($group),
        );
    }

    public function test_g4_doubles_persists_official_playing_order(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition();
        $players = $context->createPlayers(8);
        $entries = $context->registerPairs($competition, [
            [$players[0], $players[1]],
            [$players[2], $players[3]],
            [$players[4], $players[5]],
            [$players[6], $players[7]],
        ]);
        $group = $context->createGroupWithEntries($competition, $entries);
        $context->generateRoundRobin($group)->assertCreated();

        $this->assertOfficialSheetSlots($group, [
            [1, 1, 1, 3],
            [1, 2, 2, 4],
            [2, 1, 1, 2],
            [2, 2, 3, 4],
            [3, 1, 1, 4],
            [3, 2, 2, 3],
        ]);
    }

    public function test_g2_keeps_berger_schedule(): void
    {
        $group = $this->generateSinglesGroup(2);

        $this->assertSame(
            $this->bergerEntrySlots($group),
            $this->persistedEntrySlots($group),
        );
        $this->assertCount(1, $this->persistedEntrySlots($group));
    }

    public function test_g6_keeps_berger_schedule(): void
    {
        $group = $this->generateSinglesGroup(6);

        $this->assertSame(
            $this->bergerEntrySlots($group),
            $this->persistedEntrySlots($group),
        );
        $this->assertCount(15, $this->persistedEntrySlots($group));
    }

    #[DataProvider('officialGroupSizeProvider')]
    public function test_regenerating_round_robin_keeps_official_pattern(int $size, array $expected): void
    {
        $group = $this->generateSinglesGroup($size);
        $before = $this->persistedSheetSlots($group);

        $this->assertSame($expected, $before);

        Game::query()->where('group_id', $group->id)->delete();

        $this->tournamentContext()->generateRoundRobin($group)->assertCreated();

        $after = $this->persistedSheetSlots($group->fresh());

        $this->assertSame($before, $after);
        $this->assertSame($expected, $after);
    }

    /**
     * @return array<string, array{0: int, 1: list<array{0: int, 1: int, 2: int, 3: int}>}>
     */
    public static function officialGroupSizeProvider(): array
    {
        return [
            'G3' => [3, [
                [1, 1, 1, 3],
                [2, 1, 1, 2],
                [3, 1, 2, 3],
            ]],
            'G4' => [4, [
                [1, 1, 1, 3],
                [1, 2, 2, 4],
                [2, 1, 1, 2],
                [2, 2, 3, 4],
                [3, 1, 1, 4],
                [3, 2, 2, 3],
            ]],
            'G5' => [5, [
                [1, 1, 2, 5],
                [1, 2, 3, 4],
                [2, 1, 1, 5],
                [2, 2, 2, 3],
                [3, 1, 1, 4],
                [3, 2, 5, 3],
                [4, 1, 1, 3],
                [4, 2, 4, 2],
                [5, 1, 1, 2],
                [5, 2, 4, 5],
            ]],
        ];
    }

    private function generateSinglesGroup(int $playerCount): Group
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers($playerCount);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);
        $context->generateRoundRobin($group)->assertCreated();

        return $group;
    }

    /**
     * @param  list<array{0: int, 1: int, 2: int, 3: int}>  $expected
     */
    private function assertOfficialSheetSlots(Group $group, array $expected): void
    {
        $slots = $this->persistedSheetSlots($group);

        $this->assertSame($expected, $slots);

        $sheetNumbers = [];

        foreach ($slots as $slot) {
            $sheetNumbers[$slot[2]] = true;
            $sheetNumbers[$slot[3]] = true;
        }

        $this->assertEachUnorderedPairOnce($slots, count($sheetNumbers));
    }

    /**
     * @param  list<array{0: int, 1: int, 2: int, 3: int}>  $slots
     */
    private function assertEachUnorderedPairOnce(array $slots, int $size): void
    {
        $seen = [];

        foreach ($slots as $slot) {
            $pair = [$slot[2], $slot[3]];
            sort($pair);
            $key = sprintf('%d-%d', $pair[0], $pair[1]);

            $this->assertArrayNotHasKey($key, $seen, sprintf('La pareja %s aparece más de una vez.', $key));
            $this->assertNotSame($slot[2], $slot[3]);
            $seen[$key] = true;
        }

        $this->assertCount(intdiv($size * ($size - 1), 2), $seen);
    }

    /**
     * @return list<array{0: int, 1: int, 2: int, 3: int}>
     */
    private function persistedSheetSlots(Group $group): array
    {
        $numbering = GroupSheetNumbering::forCompetitionEntryIds(
            $group->groupEntries()
                ->pluck('competition_entry_id')
                ->map(fn ($id): int => (int) $id)
                ->all(),
        );

        return Game::query()
            ->where('group_id', $group->id)
            ->orderBy('group_round')
            ->orderBy('group_match')
            ->orderBy('id')
            ->get()
            ->map(fn (Game $game): array => [
                (int) $game->group_round,
                (int) $game->group_match,
                $numbering[(int) $game->entry1_id],
                $numbering[(int) $game->entry2_id],
            ])
            ->all();
    }

    /**
     * @return list<array{0: int, 1: int, 2: int, 3: int}>
     */
    private function persistedEntrySlots(Group $group): array
    {
        return Game::query()
            ->where('group_id', $group->id)
            ->orderBy('group_round')
            ->orderBy('group_match')
            ->orderBy('id')
            ->get()
            ->map(fn (Game $game): array => [
                (int) $game->group_round,
                (int) $game->group_match,
                (int) $game->entry1_id,
                (int) $game->entry2_id,
            ])
            ->all();
    }

    /**
     * @return list<array{0: int, 1: int, 2: int, 3: int}>
     */
    private function bergerEntrySlots(Group $group): array
    {
        $entryIds = $group->groupEntries()
            ->orderBy('competition_entry_id')
            ->pluck('competition_entry_id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        $slots = [];

        foreach (app(RoundRobinScheduleBuilder::class)->build($entryIds) as $roundIndex => $roundPairings) {
            foreach ($roundPairings as $matchIndex => $pairing) {
                $slots[] = [
                    $roundIndex + 1,
                    $matchIndex + 1,
                    $pairing['entry1_id'],
                    $pairing['entry2_id'],
                ];
            }
        }

        return $slots;
    }

    private function createSinglesEntryWithId(Competition $competition, Player $player, int $id): CompetitionEntry
    {
        $entry = CompetitionEntry::query()->create([
            'id' => $id,
            'competition_id' => $competition->id,
            'status' => CompetitionEntryStatus::Active,
        ]);

        CompetitionEntryMember::query()->create([
            'competition_entry_id' => $entry->id,
            'competition_id' => $competition->id,
            'player_id' => $player->id,
            'member_order' => 1,
        ]);

        return $entry->load('members.player');
    }
}
