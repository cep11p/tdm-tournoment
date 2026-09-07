<?php

namespace Tests\Feature\Seed;

use App\Enums\BracketGamePurpose;
use App\Enums\CompetitionFormat;
use App\Enums\CompetitionType;
use App\Enums\GameStatus;
use App\Enums\TeamTieStatus;
use App\Enums\TournamentStatus;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\CompetitionEntryMember;
use App\Models\CompetitionFinalStanding;
use App\Models\Game;
use App\Models\Group;
use App\Models\GroupEntry;
use App\Models\Player;
use App\Models\PlayingTable;
use App\Models\Ranking;
use App\Models\RankingStanding;
use App\Models\RankingTransaction;
use App\Models\TeamTie;
use App\Models\TeamTieGame;
use App\Models\Tournament;
use App\Support\Competition\CompetitionResultResolver;
use App\Support\Competition\CompetitionStatusResolver;
use App\Support\Group\GroupStandingsResolver;
use Database\Seeders\DemoArchivedTournamentSeeder;
use Database\Seeders\DemoPlayersSeeder;
use Database\Seeders\DemoTournamentSeeder;
use Database\Seeders\RankingSeeder;
use Database\Seeders\Support\DemoPlayerCatalog;
use Database\Seeders\Support\DemoScenarioRunner;
use Database\Seeders\Support\Scenarios\DoublesCompletedScenario;
use Database\Seeders\Support\Scenarios\SinglesCompletedScenario;
use Database\Seeders\Support\Scenarios\SinglesGroupsInProgressScenario;
use Database\Seeders\Support\Scenarios\SinglesKnockoutInProgressScenario;
use Database\Seeders\Support\Scenarios\SinglesRegistrationScenario;
use Database\Seeders\Support\Scenarios\TeamKnockoutInProgressScenario;
use Database\Seeders\TeamTieFormatSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class DemoSeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            TeamTieFormatSeeder::class,
            RankingSeeder::class,
            DemoPlayersSeeder::class,
            DemoTournamentSeeder::class,
            DemoArchivedTournamentSeeder::class,
        ]);
    }

    public function test_demo_seed_runs_without_exceptions(): void
    {
        $this->assertTrue(true);
    }

    public function test_creates_exactly_sixteen_demo_players_and_preserves_historical_singles_seeds(): void
    {
        $nicknames = collect(DemoPlayerCatalog::definitions())
            ->pluck('nickname')
            ->all();

        $this->assertCount(16, $nicknames);
        $this->assertCount(8, DemoPlayerCatalog::SINGLES_SEEDS);
        $this->assertSame(16, Player::query()->whereIn('nickname', $nicknames)->count());
        $this->assertSame(16, Player::query()->where('nickname', 'like', 'demo-%')->count());

        foreach (DemoPlayerCatalog::SINGLES_SEEDS as $seed) {
            $this->assertDatabaseHas('players', [
                'nickname' => DemoPlayerCatalog::nicknameForSeed($seed),
            ]);
        }
    }

    public function test_demo_tournaments_exist_with_expected_names(): void
    {
        $this->assertDatabaseHas('tournaments', ['name' => DemoScenarioRunner::TOURNAMENT_ACTIVE]);
        $this->assertDatabaseHas('tournaments', ['name' => DemoScenarioRunner::TOURNAMENT_ARCHIVED]);
    }

    public function test_archived_tournament_is_finished_via_domain_closure(): void
    {
        $tournament = Tournament::query()
            ->where('name', DemoScenarioRunner::TOURNAMENT_ARCHIVED)
            ->firstOrFail();

        $this->assertSame(TournamentStatus::Finished, $tournament->status);
        $this->assertNotNull($tournament->closed_at);
    }

    public function test_active_tournament_has_four_unassigned_playing_tables(): void
    {
        $tournament = Tournament::query()
            ->where('name', DemoScenarioRunner::TOURNAMENT_ACTIVE)
            ->firstOrFail();

        $tables = $tournament->playingTables()->get();

        $this->assertCount(DemoScenarioRunner::PLAYING_TABLE_COUNT, $tables);
        $this->assertSame([1, 2, 3, 4], $tables->pluck('number')->all());
        $this->assertSame([1, 2, 3, 4], $tables->pluck('sort_order')->all());
        $this->assertTrue($tables->every(fn (PlayingTable $table): bool => $table->active));
        $this->assertTrue($tables->every(fn (PlayingTable $table): bool => $table->name === null));
        $this->assertSame(
            ['Mesa 1', 'Mesa 2', 'Mesa 3', 'Mesa 4'],
            $tables->map(fn (PlayingTable $table): string => $table->displayName())->all(),
        );
        $this->assertSame(0, Game::query()->whereNotNull('playing_table_id')->count());
    }

    public function test_archived_tournament_does_not_receive_playing_tables(): void
    {
        $tournament = Tournament::query()
            ->where('name', DemoScenarioRunner::TOURNAMENT_ARCHIVED)
            ->firstOrFail();

        $this->assertSame(0, $tournament->playingTables()->count());
    }

    public function test_singles_registration_has_eight_entries_without_groups(): void
    {
        $competition = $this->competitionInActiveTournament(SinglesRegistrationScenario::COMPETITION_NAME);

        $this->assertSame(8, $competition->entries()->count());
        $this->assertSame(0, $competition->groups()->count());
        $this->assertSame('no_groups', CompetitionStatusResolver::resolve($competition)['code']);
    }

    public function test_singles_registration_has_five_present_and_three_pending_without_games(): void
    {
        $competition = $this->competitionInActiveTournament(SinglesRegistrationScenario::COMPETITION_NAME);
        $members = $this->membersForCompetition($competition);

        $this->assertCount(8, $members);
        $this->assertSame(0, Game::query()->where('competition_id', $competition->id)->count());

        $presentNicknames = SinglesRegistrationScenario::checkedInNicknames();
        $present = $members->filter(fn (CompetitionEntryMember $member): bool => $member->isCheckedIn());
        $pending = $members->filter(fn (CompetitionEntryMember $member): bool => ! $member->isCheckedIn());

        $this->assertCount(5, $present);
        $this->assertCount(3, $pending);
        $this->assertEqualsCanonicalizing(
            $presentNicknames,
            $present->map(fn (CompetitionEntryMember $member): string => (string) $member->player?->nickname)->all(),
        );
        $this->assertEqualsCanonicalizing(
            array_values(array_diff(
                array_map(
                    fn (int $seed): string => DemoPlayerCatalog::nicknameForSeed($seed),
                    DemoPlayerCatalog::SINGLES_SEEDS,
                ),
                $presentNicknames,
            )),
            $pending->map(fn (CompetitionEntryMember $member): string => (string) $member->player?->nickname)->all(),
        );

        $checkedInAt = $present->first()?->checked_in_at;
        $this->assertNotNull($checkedInAt);
        $this->assertTrue(
            $present->every(fn (CompetitionEntryMember $member): bool => $member->checked_in_at?->equalTo($checkedInAt)),
        );
    }

    public function test_demo_games_with_activity_have_checked_in_entry_members(): void
    {
        Game::query()
            ->with(['sets', 'entry1.members', 'entry2.members'])
            ->get()
            ->filter(fn (Game $game): bool => $this->gameHasActivity($game))
            ->each(function (Game $game): void {
                foreach ([$game->entry1, $game->entry2] as $entry) {
                    if ($entry === null) {
                        continue;
                    }

                    foreach ($entry->members as $member) {
                        $this->assertNotNull(
                            $member->checked_in_at,
                            sprintf('Member %d of game %d should be checked in.', $member->id, $game->id),
                        );
                    }
                }
            });
    }

    public function test_demo_team_rubbers_with_activity_have_checked_in_lineup_members(): void
    {
        TeamTieGame::query()
            ->with(['game.sets', 'members.competitionEntryMember'])
            ->get()
            ->filter(fn (TeamTieGame $rubber): bool => $rubber->game !== null && $this->gameHasActivity($rubber->game))
            ->each(function (TeamTieGame $rubber): void {
                $this->assertNotEmpty($rubber->members);

                foreach ($rubber->members as $lineupMember) {
                    $member = $lineupMember->competitionEntryMember;

                    $this->assertNotNull($member);
                    $this->assertNotNull(
                        $member->checked_in_at,
                        sprintf(
                            'Lineup member %d of rubber %d should be checked in.',
                            $lineupMember->id,
                            $rubber->id,
                        ),
                    );
                }
            });
    }

    public function test_demo_in_progress_and_completed_scenarios_have_no_pending_check_in(): void
    {
        $competitions = [
            $this->competitionInActiveTournament(SinglesGroupsInProgressScenario::COMPETITION_NAME),
            $this->competitionInActiveTournament(SinglesKnockoutInProgressScenario::COMPETITION_NAME),
            $this->competitionInActiveTournament(TeamKnockoutInProgressScenario::COMPETITION_NAME),
            $this->competitionInArchivedTournament(SinglesCompletedScenario::COMPETITION_NAME),
            $this->competitionInArchivedTournament(DoublesCompletedScenario::COMPETITION_NAME),
        ];

        foreach ($competitions as $competition) {
            $pending = $this->membersForCompetition($competition)
                ->filter(fn (CompetitionEntryMember $member): bool => ! $member->isCheckedIn())
                ->count();

            $this->assertSame(
                0,
                $pending,
                sprintf('%s should have no pending check-in members.', $competition->name),
            );
        }
    }

    public function test_singles_groups_in_progress_has_partial_games_and_manual_tiebreak(): void
    {
        $competition = $this->competitionInActiveTournament(SinglesGroupsInProgressScenario::COMPETITION_NAME);

        $this->assertSame(2, $competition->groups()->count());

        $groupA = Group::query()
            ->where('competition_id', $competition->id)
            ->where('name', 'Grupo A')
            ->firstOrFail();

        $groupB = Group::query()
            ->where('competition_id', $competition->id)
            ->where('name', 'Grupo B')
            ->firstOrFail();

        $groupAGames = Game::query()->where('group_id', $groupA->id)->get();
        $finishedGroupA = $groupAGames->where('status', GameStatus::Finished)->count();
        $pendingGroupA = $groupAGames->where('status', GameStatus::Pending)->count();

        $this->assertGreaterThan(0, $finishedGroupA);
        $this->assertGreaterThan(0, $pendingGroupA);

        $groupBStandings = app(GroupStandingsResolver::class)->calculate($groupB);
        $this->assertTrue($groupBStandings->requiresManualTiebreak());
        $this->assertSame('group_stage_in_progress', CompetitionStatusResolver::resolve($competition)['code']);
    }

    public function test_singles_knockout_in_progress_has_bracket_and_is_not_completed(): void
    {
        $competition = $this->competitionInActiveTournament(SinglesKnockoutInProgressScenario::COMPETITION_NAME);

        $this->assertTrue($competition->brackets()->exists());

        $pendingBracketGames = Game::query()
            ->where('competition_id', $competition->id)
            ->whereNotNull('bracket_id')
            ->where('status', GameStatus::Pending)
            ->count();

        $this->assertGreaterThan(0, $pendingBracketGames);
        $this->assertSame('knockout_in_progress', CompetitionStatusResolver::resolve($competition)['code']);
    }

    public function test_singles_completed_is_completed_with_champion_carlos_perez(): void
    {
        $competition = $this->competitionInArchivedTournament(SinglesCompletedScenario::COMPETITION_NAME);

        $this->assertSame('completed', CompetitionStatusResolver::resolve($competition)['code']);

        $result = CompetitionResultResolver::resolve($competition);
        $this->assertNotNull($result);
        $this->assertSame('Carlos', $result['champion']['members'][0]['first_name']);
        $this->assertSame('Perez', $result['champion']['members'][0]['last_name']);

        $final = Game::query()
            ->where('competition_id', $competition->id)
            ->where('round', 'Final')
            ->firstOrFail();

        $this->assertSame(GameStatus::Finished, $final->status);
        $this->assertNotNull($final->winner_entry_id);

        $thirdPlace = Game::query()
            ->where('competition_id', $competition->id)
            ->where('bracket_purpose', BracketGamePurpose::ThirdPlace)
            ->firstOrFail();

        $this->assertSame(GameStatus::Finished, $thirdPlace->status);
        $this->assertNotNull($thirdPlace->winner_entry_id);

        $this->assertSame(8, $competition->finalStandings()->count());
    }

    public function test_doubles_completed_has_four_pair_entries_and_is_completed(): void
    {
        $competition = $this->competitionInArchivedTournament(DoublesCompletedScenario::COMPETITION_NAME);

        $entries = $competition->entries()->with('members')->get();

        $this->assertCount(4, $entries);

        foreach ($entries as $entry) {
            $this->assertCount(2, $entry->members);
        }

        $this->assertSame('completed', CompetitionStatusResolver::resolve($competition)['code']);

        $result = CompetitionResultResolver::resolve($competition);
        $this->assertNotNull($result);
        $this->assertCount(2, $result['champion']['members']);
        $this->assertSame(4, $competition->finalStandings()->count());
    }

    public function test_stage_e_ranks_completed_singles_and_doubles_but_not_team(): void
    {
        $singlesRanking = Ranking::query()->where('name', RankingSeeder::SINGLES_NAME)->firstOrFail();
        $doublesRanking = Ranking::query()->where('name', RankingSeeder::DOUBLES_NAME)->firstOrFail();

        $this->assertTrue($singlesRanking->active);
        $this->assertTrue($doublesRanking->active);
        $this->assertSame(CompetitionType::Singles, $singlesRanking->competition_type);
        $this->assertSame(CompetitionType::Doubles, $doublesRanking->competition_type);
        $this->assertSame(RankingSeeder::SEASON, $singlesRanking->season);
        $this->assertNull($singlesRanking->category_id);
        $this->assertSame(0, Ranking::query()->where('competition_type', CompetitionType::Team)->count());

        $singles = $this->competitionInArchivedTournament(SinglesCompletedScenario::COMPETITION_NAME);
        $doubles = $this->competitionInArchivedTournament(DoublesCompletedScenario::COMPETITION_NAME);
        $team = $this->competitionInActiveTournament(TeamKnockoutInProgressScenario::COMPETITION_NAME);

        $this->assertSame(8, $singles->finalStandings()->count());
        $this->assertSame(4, $doubles->finalStandings()->count());

        $this->assertSame(8, RankingTransaction::query()
            ->where('ranking_id', $singlesRanking->id)
            ->where('competition_id', $singles->id)
            ->count());
        $this->assertSame(8, RankingTransaction::query()
            ->where('ranking_id', $doublesRanking->id)
            ->where('competition_id', $doubles->id)
            ->count());
        $this->assertSame(0, RankingTransaction::query()->where('competition_id', $team->id)->count());
        $this->assertSame(0, RankingTransaction::query()->where('competition_type', CompetitionType::Team)->count());

        $this->assertGreaterThan(0, RankingStanding::query()->where('ranking_id', $singlesRanking->id)->count());
        $this->assertGreaterThan(0, RankingStanding::query()->where('ranking_id', $doublesRanking->id)->count());
        $this->assertSame(8, RankingStanding::query()->where('ranking_id', $singlesRanking->id)->sum('events_count'));
        $this->assertSame(8, RankingStanding::query()->where('ranking_id', $doublesRanking->id)->sum('events_count'));
    }

    public function test_domain_integrity_for_entries_groups_and_games(): void
    {
        Game::query()->with('competition')->get()->each(function (Game $game): void {
            $this->assertNotNull($game->competition);
            $this->assertSame($game->competition_id, $game->competition->id);
        });

        GroupEntry::query()->with(['group', 'competitionEntry'])->get()->each(function (GroupEntry $groupEntry): void {
            $this->assertSame($groupEntry->group->competition_id, $groupEntry->competition_id);
            $this->assertSame($groupEntry->competitionEntry->competition_id, $groupEntry->competition_id);
        });

        CompetitionEntryMember::query()->with('competitionEntry')->get()->each(function (CompetitionEntryMember $member): void {
            $this->assertSame($member->competitionEntry->competition_id, $member->competition_id);
            $this->assertSame($member->competitionEntry->id, $member->competition_entry_id);
        });

        $this->assertSame(
            0,
            Game::query()
                ->whereNull('competition_id')
                ->orWhereDoesntHave('competition')
                ->count(),
        );
    }

    public function test_team_knockout_in_progress_covers_stage_c_and_print_smoke(): void
    {
        $competition = $this->competitionInActiveTournament(TeamKnockoutInProgressScenario::COMPETITION_NAME);

        $this->assertSame(CompetitionType::Team, $competition->type);
        $this->assertSame(CompetitionFormat::GroupsKnockout, $competition->format);
        $this->assertSame(4, $competition->team_size);
        $this->assertSame(2, $competition->qualified_per_group);
        $this->assertSame('shared', $competition->third_place_mode->value);

        $competition->load('teamTieFormat');
        $this->assertSame(TeamKnockoutInProgressScenario::TEAM_TIE_FORMAT_NAME, $competition->teamTieFormat?->name);

        $entries = $competition->entries()->with('members.player')->orderBy('id')->get();
        $this->assertCount(4, $entries);

        $byName = $entries->keyBy('display_name');
        $this->assertTrue($byName->has(TeamKnockoutInProgressScenario::TEAM_ANDES));
        $this->assertTrue($byName->has(TeamKnockoutInProgressScenario::TEAM_PATAGONIA));
        $this->assertTrue($byName->has(TeamKnockoutInProgressScenario::TEAM_LAGOS));
        $this->assertTrue($byName->has(TeamKnockoutInProgressScenario::TEAM_VALLE));

        $this->assertSame(
            ['Carlos Perez', 'Martin Castro', 'Pablo Romero', 'Felipe Rios'],
            $this->rosterNames($byName[TeamKnockoutInProgressScenario::TEAM_ANDES]),
        );
        $this->assertSame(
            ['Juan Gomez', 'Luis Lopez', 'Andres Vega', 'Sergio Aguilar'],
            $this->rosterNames($byName[TeamKnockoutInProgressScenario::TEAM_PATAGONIA]),
        );
        $this->assertSame(
            ['Pedro Ruiz', 'Nicolas Torres', 'Javier Soto', 'Bruno Medina'],
            $this->rosterNames($byName[TeamKnockoutInProgressScenario::TEAM_LAGOS]),
        );
        $this->assertSame(
            ['Marcos Diaz', 'Diego Silva', 'Tomas Herrera', 'Hernan Molina'],
            $this->rosterNames($byName[TeamKnockoutInProgressScenario::TEAM_VALLE]),
        );

        $this->assertSame(1, $competition->groups()->count());

        $group = Group::query()
            ->where('competition_id', $competition->id)
            ->where('name', TeamKnockoutInProgressScenario::GROUP_NAME)
            ->firstOrFail();

        $groupTies = TeamTie::query()->where('group_id', $group->id)->get();
        $this->assertCount(6, $groupTies);
        $this->assertTrue($groupTies->every(fn (TeamTie $teamTie): bool => $teamTie->status === TeamTieStatus::Finished));

        $this->assertSame(0, Game::query()->where('competition_id', $competition->id)->whereNotNull('group_id')->count());

        $rubberGames = Game::query()
            ->where('competition_id', $competition->id)
            ->whereHas('teamTieGame')
            ->get();

        $this->assertGreaterThan(0, $rubberGames->count());
        $rubberGames->each(function (Game $game): void {
            $this->assertNull($game->group_id);
            $this->assertNull($game->bracket_id);
        });

        $andesValle = $this->teamTieBetween(
            $groupTies,
            (int) $byName[TeamKnockoutInProgressScenario::TEAM_ANDES]->id,
            (int) $byName[TeamKnockoutInProgressScenario::TEAM_VALLE]->id,
        );

        $notNeededCount = Game::query()
            ->whereHas('teamTieGame', fn ($query) => $query->where('team_tie_id', $andesValle->id))
            ->where('status', GameStatus::NotNeeded)
            ->count();

        $this->assertSame(2, $notNeededCount);

        $standings = app(GroupStandingsResolver::class)->calculate($group);
        $this->assertFalse($standings->requiresManualTiebreak());
        $this->assertSame(
            [
                TeamKnockoutInProgressScenario::TEAM_ANDES,
                TeamKnockoutInProgressScenario::TEAM_PATAGONIA,
                TeamKnockoutInProgressScenario::TEAM_LAGOS,
                TeamKnockoutInProgressScenario::TEAM_VALLE,
            ],
            $standings->standings->pluck('displayName')->all(),
        );
        $this->assertSame([3, 2, 1, 0], $standings->standings->pluck('won')->all());
        $this->assertSame([0, 1, 2, 3], $standings->standings->pluck('lost')->all());

        $this->assertTrue($competition->brackets()->exists());

        $final = TeamTie::query()
            ->where('competition_id', $competition->id)
            ->where('round', 'Final')
            ->firstOrFail();

        $finalParticipantIds = [(int) $final->entry1_id, (int) $final->entry2_id];
        $this->assertContains((int) $byName[TeamKnockoutInProgressScenario::TEAM_ANDES]->id, $finalParticipantIds);
        $this->assertContains((int) $byName[TeamKnockoutInProgressScenario::TEAM_PATAGONIA]->id, $finalParticipantIds);
        $this->assertSame(TeamTieStatus::InProgress, $final->status);
        $this->assertNull($final->winner_entry_id);

        $finalScore = $this->officialScoreByTeamName($final, $byName);
        $this->assertSame(1, $finalScore[TeamKnockoutInProgressScenario::TEAM_ANDES]);
        $this->assertSame(0, $finalScore[TeamKnockoutInProgressScenario::TEAM_PATAGONIA]);

        $this->assertSame('knockout_in_progress', CompetitionStatusResolver::resolve($competition)['code']);
        $this->assertSame(0, TeamTie::query()->where('competition_id', $competition->id)->thirdPlace()->count());
        $this->assertSame(0, $competition->finalStandings()->count());
        $this->assertSame(0, CompetitionFinalStanding::query()->where('competition_id', $competition->id)->count());
    }

    public function test_team_print_endpoints_return_contractual_payloads(): void
    {
        $competition = $this->competitionInActiveTournament(TeamKnockoutInProgressScenario::COMPETITION_NAME);
        $andes = $competition->entries()->where('display_name', TeamKnockoutInProgressScenario::TEAM_ANDES)->firstOrFail();
        $valle = $competition->entries()->where('display_name', TeamKnockoutInProgressScenario::TEAM_VALLE)->firstOrFail();

        $group = Group::query()
            ->where('competition_id', $competition->id)
            ->where('name', TeamKnockoutInProgressScenario::GROUP_NAME)
            ->firstOrFail();

        $andesValle = $this->teamTieBetween(
            TeamTie::query()->where('group_id', $group->id)->get(),
            (int) $andes->id,
            (int) $valle->id,
        );

        $final = TeamTie::query()
            ->where('competition_id', $competition->id)
            ->where('round', 'Final')
            ->firstOrFail();

        $bracketPrint = $this->getJson("/api/v1/competitions/{$competition->id}/bracket/print")
            ->assertOk()
            ->assertJsonPath('data.competition.type', 'team')
            ->json('data');

        $this->assertSame('Final', $bracketPrint['rounds'][0]['label']);
        $finalMatch = $bracketPrint['rounds'][0]['matches'][0];
        $sideNames = [
            $finalMatch['side1']['display_name'] ?? null,
            $finalMatch['side2']['display_name'] ?? null,
        ];
        $this->assertContains(TeamKnockoutInProgressScenario::TEAM_ANDES, $sideNames);
        $this->assertContains(TeamKnockoutInProgressScenario::TEAM_PATAGONIA, $sideNames);
        $this->assertNull($bracketPrint['champion']);

        $groupPrint = $this->getJson("/api/v1/team-ties/{$andesValle->id}/print")
            ->assertOk()
            ->assertJsonPath('data.format.name', TeamKnockoutInProgressScenario::TEAM_TIE_FORMAT_NAME)
            ->assertJsonPath('data.team_tie.status', 'finished')
            ->json('data');

        $groupScores = [$groupPrint['score']['side1'], $groupPrint['score']['side2']];
        sort($groupScores);
        $this->assertSame([0, 3], $groupScores);
        $this->assertSame(TeamKnockoutInProgressScenario::TEAM_ANDES, $groupPrint['winner']['display_name']);
        $this->assertSame('doubles', $groupPrint['rubbers'][2]['type']);
        $this->assertSame('not_needed', $groupPrint['rubbers'][3]['status']);
        $this->assertSame('not_needed', $groupPrint['rubbers'][4]['status']);
        $this->assertNotEmpty($groupPrint['rubbers'][3]['side1']['players']);
        $this->assertNotEmpty($groupPrint['rubbers'][3]['side2']['players']);
        $this->assertNotEmpty($groupPrint['rubbers'][4]['side1']['players']);
        $this->assertNotEmpty($groupPrint['rubbers'][4]['side2']['players']);

        $finalPrint = $this->getJson("/api/v1/team-ties/{$final->id}/print")
            ->assertOk()
            ->assertJsonPath('data.team_tie.status', 'in_progress')
            ->json('data');

        $finalScores = [$finalPrint['score']['side1'], $finalPrint['score']['side2']];
        sort($finalScores);
        $this->assertSame([0, 1], $finalScores);
        $this->assertSame('finished', $finalPrint['rubbers'][0]['status']);
        $this->assertSame('pending', $finalPrint['rubbers'][1]['status']);
        $this->assertSame('pending', $finalPrint['rubbers'][2]['status']);
        $this->assertSame('pending', $finalPrint['rubbers'][3]['status']);
        $this->assertSame('pending', $finalPrint['rubbers'][4]['status']);
    }

    /**
     * @return Collection<int, CompetitionEntryMember>
     */
    private function membersForCompetition(Competition $competition): Collection
    {
        return CompetitionEntryMember::query()
            ->where('competition_id', $competition->id)
            ->with('player:id,nickname')
            ->get();
    }

    private function gameHasActivity(Game $game): bool
    {
        $status = $game->status instanceof GameStatus
            ? $game->status
            : GameStatus::from((string) $game->status);

        if ($status === GameStatus::Finished || $status === GameStatus::InProgress) {
            return true;
        }

        $sets = $game->relationLoaded('sets') ? $game->sets : $game->sets()->get();

        return $sets->isNotEmpty();
    }

    private function competitionInActiveTournament(string $name): Competition
    {
        $tournament = Tournament::query()
            ->where('name', DemoScenarioRunner::TOURNAMENT_ACTIVE)
            ->firstOrFail();

        return Competition::query()
            ->where('tournament_id', $tournament->id)
            ->where('name', $name)
            ->firstOrFail();
    }

    private function competitionInArchivedTournament(string $name): Competition
    {
        $tournament = Tournament::query()
            ->where('name', DemoScenarioRunner::TOURNAMENT_ARCHIVED)
            ->firstOrFail();

        return Competition::query()
            ->where('tournament_id', $tournament->id)
            ->where('name', $name)
            ->firstOrFail();
    }

    /**
     * @return list<string>
     */
    private function rosterNames(CompetitionEntry $entry): array
    {
        return $entry->members
            ->sortBy('member_order')
            ->values()
            ->map(fn (CompetitionEntryMember $member): string => trim(sprintf(
                '%s %s',
                $member->player?->first_name,
                $member->player?->last_name,
            )))
            ->all();
    }

    /**
     * @param  Collection<int, TeamTie>  $teamTies
     */
    private function teamTieBetween($teamTies, int $entry1Id, int $entry2Id): TeamTie
    {
        $teamTie = $teamTies->first(
            fn (TeamTie $candidate): bool => (
                (int) $candidate->entry1_id === $entry1Id && (int) $candidate->entry2_id === $entry2Id
            ) || (
                (int) $candidate->entry1_id === $entry2Id && (int) $candidate->entry2_id === $entry1Id
            )
        );

        $this->assertNotNull($teamTie);

        return $teamTie;
    }

    /**
     * @param  Collection<string, CompetitionEntry>  $entriesByName
     * @return array<string, int>
     */
    private function officialScoreByTeamName(TeamTie $teamTie, $entriesByName): array
    {
        $teamTie->loadMissing(['teamTieGames.game', 'entry1', 'entry2']);

        $scores = [
            (string) $teamTie->entry1?->display_name => 0,
            (string) $teamTie->entry2?->display_name => 0,
        ];

        foreach ($teamTie->teamTieGames as $rubber) {
            $game = $rubber->game;

            if ($game === null || $game->status !== GameStatus::Finished || $game->winner_entry_id === null) {
                continue;
            }

            $winnerName = (int) $game->winner_entry_id === (int) $entriesByName[TeamKnockoutInProgressScenario::TEAM_ANDES]->id
                ? TeamKnockoutInProgressScenario::TEAM_ANDES
                : (
                    (int) $game->winner_entry_id === (int) $entriesByName[TeamKnockoutInProgressScenario::TEAM_PATAGONIA]->id
                        ? TeamKnockoutInProgressScenario::TEAM_PATAGONIA
                        : (string) $game->winnerEntry?->display_name
                );

            if ($winnerName === '') {
                continue;
            }

            $scores[$winnerName] = ($scores[$winnerName] ?? 0) + 1;
        }

        return $scores;
    }
}
