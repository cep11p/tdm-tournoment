<?php

namespace Tests\Feature\Seed;

use App\Enums\BracketGamePurpose;
use App\Enums\GameStatus;
use App\Enums\TournamentStatus;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\CompetitionEntryMember;
use App\Models\Game;
use App\Models\Group;
use App\Models\GroupEntry;
use App\Models\Player;
use App\Models\Tournament;
use Database\Seeders\DemoArchivedTournamentSeeder;
use Database\Seeders\DemoPlayersSeeder;
use Database\Seeders\DemoTournamentSeeder;
use Database\Seeders\Support\DemoPlayerCatalog;
use Database\Seeders\Support\DemoScenarioRunner;
use Database\Seeders\Support\Scenarios\DoublesCompletedScenario;
use Database\Seeders\Support\Scenarios\SinglesGroupsInProgressScenario;
use Database\Seeders\Support\Scenarios\SinglesKnockoutInProgressScenario;
use Database\Seeders\Support\Scenarios\SinglesRegistrationScenario;
use Database\Seeders\TeamTieFormatSeeder;
use Database\Seeders\Support\Scenarios\SinglesCompletedScenario;
use App\Support\Competition\CompetitionResultResolver;
use App\Support\Competition\CompetitionStatusResolver;
use App\Support\Group\GroupStandingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoSeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            TeamTieFormatSeeder::class,
            DemoPlayersSeeder::class,
            DemoTournamentSeeder::class,
            DemoArchivedTournamentSeeder::class,
        ]);
    }

    public function test_demo_seed_runs_without_exceptions(): void
    {
        $this->assertTrue(true);
    }

    public function test_creates_exactly_eight_historical_players_by_nickname(): void
    {
        $nicknames = collect(DemoPlayerCatalog::definitions())
            ->pluck('nickname')
            ->all();

        $this->assertCount(8, $nicknames);
        $this->assertSame(8, Player::query()->whereIn('nickname', $nicknames)->count());
        $this->assertSame(8, Player::query()->where('nickname', 'like', 'demo-%')->count());
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

    public function test_singles_registration_has_eight_entries_without_groups(): void
    {
        $competition = $this->competitionInActiveTournament(SinglesRegistrationScenario::COMPETITION_NAME);

        $this->assertSame(8, $competition->entries()->count());
        $this->assertSame(0, $competition->groups()->count());
        $this->assertSame('no_groups', CompetitionStatusResolver::resolve($competition)['code']);
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
}
