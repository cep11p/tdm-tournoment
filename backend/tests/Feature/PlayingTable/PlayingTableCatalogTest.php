<?php

namespace Tests\Feature\PlayingTable;

use App\Models\Game;
use App\Models\PlayingTable;
use App\Models\TeamTie;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlayingTableCatalogTest extends TestCase
{
    public function test_playing_table_belongs_to_tournament_and_tournament_has_many_tables(): void
    {
        $tournament = $this->tournamentContext()->createTournament();

        $first = PlayingTable::factory()->create([
            'tournament_id' => $tournament->id,
            'number' => 1,
            'sort_order' => 1,
        ]);
        $second = PlayingTable::factory()->create([
            'tournament_id' => $tournament->id,
            'number' => 2,
            'sort_order' => 2,
        ]);

        $this->assertTrue($first->tournament->is($tournament));
        $this->assertSame(
            [$first->id, $second->id],
            $tournament->playingTables()->pluck('id')->all(),
        );
    }

    public function test_number_is_unique_within_the_same_tournament(): void
    {
        $tournament = $this->tournamentContext()->createTournament();

        PlayingTable::factory()->create([
            'tournament_id' => $tournament->id,
            'number' => 1,
            'sort_order' => 1,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        PlayingTable::factory()->create([
            'tournament_id' => $tournament->id,
            'number' => 1,
            'sort_order' => 2,
        ]);
    }

    public function test_same_number_is_allowed_on_different_tournaments(): void
    {
        $context = $this->tournamentContext();
        $firstTournament = $context->createTournament(['name' => 'Torneo A']);
        $secondTournament = $context->createTournament(['name' => 'Torneo B']);

        $first = PlayingTable::factory()->create([
            'tournament_id' => $firstTournament->id,
            'number' => 1,
            'sort_order' => 1,
        ]);
        $second = PlayingTable::factory()->create([
            'tournament_id' => $secondTournament->id,
            'number' => 1,
            'sort_order' => 1,
        ]);

        $this->assertSame(1, $first->number);
        $this->assertSame(1, $second->number);
        $this->assertNotSame($first->tournament_id, $second->tournament_id);
    }

    public function test_display_name_uses_name_when_present_and_falls_back_to_mesa_number(): void
    {
        $tournament = $this->tournamentContext()->createTournament();

        $numbered = PlayingTable::factory()->create([
            'tournament_id' => $tournament->id,
            'number' => 2,
            'name' => null,
            'sort_order' => 2,
        ]);
        $named = PlayingTable::factory()->named('Mesa Central')->create([
            'tournament_id' => $tournament->id,
            'number' => 3,
            'sort_order' => 3,
        ]);

        $this->assertSame('Mesa 2', $numbered->displayName());
        $this->assertSame('Mesa Central', $named->displayName());
    }

    public function test_game_can_have_null_playing_table_id(): void
    {
        $setup = $this->tournamentContext()->createPendingSinglesGame();

        $this->assertNull($setup['game']->playing_table_id);
        $this->assertNull($setup['game']->playingTable);
    }

    public function test_game_belongs_to_playing_table(): void
    {
        $setup = $this->tournamentContext()->createPendingSinglesGame();
        $table = PlayingTable::factory()->create([
            'tournament_id' => $setup['competition']->tournament_id,
            'number' => 1,
            'sort_order' => 1,
        ]);

        $setup['game']->update(['playing_table_id' => $table->id]);

        $game = $setup['game']->fresh('playingTable');

        $this->assertSame($table->id, $game->playing_table_id);
        $this->assertTrue($game->playingTable->is($table));
        $this->assertTrue($table->games()->whereKey($game->id)->exists());
    }

    public function test_a_playing_table_cannot_be_assigned_to_two_games_at_once(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame();
        $table = PlayingTable::factory()->create([
            'tournament_id' => $setup['competition']->tournament_id,
            'number' => 1,
            'sort_order' => 1,
        ]);

        $setup['game']->update(['playing_table_id' => $table->id]);

        $extraPlayers = $context->createPlayers(2);
        $context->registerPlayers($setup['competition'], $extraPlayers);
        $secondGame = $context->persistGame($setup['competition'], $extraPlayers[0], $extraPlayers[1]);

        $this->expectException(UniqueConstraintViolationException::class);

        $secondGame->update(['playing_table_id' => $table->id]);
    }

    public function test_multiple_games_may_have_null_playing_table_id(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame();

        $extraPlayers = $context->createPlayers(2);
        $context->registerPlayers($setup['competition'], $extraPlayers);
        $secondGame = $context->persistGame($setup['competition'], $extraPlayers[0], $extraPlayers[1]);

        $this->assertNull($setup['game']->fresh()->playing_table_id);
        $this->assertNull($secondGame->fresh()->playing_table_id);
        $this->assertSame(
            2,
            Game::query()
                ->whereIn('id', [$setup['game']->id, $secondGame->id])
                ->whereNull('playing_table_id')
                ->count(),
        );
    }

    public function test_a_team_tie_rubber_game_can_belong_to_a_playing_table(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $entries = $context->registerTeams($competition, 2, 4);
        $group = $context->createGroupWithEntries($competition, $entries);
        $context->generateTeamRoundRobin($group)->assertCreated();

        $teamTie = TeamTie::query()->where('group_id', $group->id)->firstOrFail();
        $rubberGame = $teamTie->teamTieGames()->orderBy('slot_order')->firstOrFail()->game;

        $this->assertNotNull($rubberGame);
        $this->assertTrue($rubberGame->teamTieGame()->exists());

        $table = PlayingTable::factory()->create([
            'tournament_id' => $competition->tournament_id,
            'number' => 1,
            'sort_order' => 1,
        ]);

        $rubberGame->update(['playing_table_id' => $table->id]);

        $this->assertSame($table->id, $rubberGame->fresh()->playing_table_id);
        $this->assertFalse(Schema::hasColumn('team_ties', 'playing_table_id'));
    }

    public function test_playing_tables_are_ordered_by_sort_order_then_number(): void
    {
        $tournament = $this->tournamentContext()->createTournament();

        PlayingTable::factory()->create([
            'tournament_id' => $tournament->id,
            'number' => 3,
            'sort_order' => 2,
        ]);
        PlayingTable::factory()->create([
            'tournament_id' => $tournament->id,
            'number' => 1,
            'sort_order' => 2,
        ]);
        PlayingTable::factory()->create([
            'tournament_id' => $tournament->id,
            'number' => 2,
            'sort_order' => 1,
        ]);

        $this->assertSame(
            [2, 1, 3],
            $tournament->playingTables()->pluck('number')->all(),
        );
    }
}
