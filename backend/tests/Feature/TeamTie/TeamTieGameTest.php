<?php

namespace Tests\Feature\TeamTie;

use App\Enums\AuditAction;
use App\Enums\CompetitionEntryStatus;
use App\Enums\GameStatus;
use App\Enums\TeamTieModality;
use App\Models\CompetitionEntryMember;
use App\Models\Game;
use App\Models\Player;
use App\Models\TeamTie;
use App\Models\TeamTieGame;
use App\Models\TeamTieGameMember;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class TeamTieGameTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_each_team_tie_generates_five_team_tie_games(): void
    {
        [$teamTie] = $this->createScheduledTeamTie();

        $this->assertSame(5, $teamTie->teamTieGames()->count());
    }

    public function test_slot_order_is_correct(): void
    {
        [$teamTie] = $this->createScheduledTeamTie();

        $this->assertSame([1, 2, 3, 4, 5], $teamTie->teamTieGames()->orderBy('slot_order')->pluck('slot_order')->all());
    }

    public function test_modality_snapshot_is_correct(): void
    {
        [$teamTie] = $this->createScheduledTeamTie();

        $this->assertSame(
            [
                TeamTieModality::Singles,
                TeamTieModality::Singles,
                TeamTieModality::Doubles,
                TeamTieModality::Singles,
                TeamTieModality::Singles,
            ],
            $teamTie->teamTieGames()->orderBy('slot_order')->pluck('modality')->all(),
        );
    }

    public function test_each_team_tie_game_has_one_game(): void
    {
        [$teamTie] = $this->createScheduledTeamTie();

        $this->assertSame(5, Game::query()->whereHas('teamTieGame', fn ($query) => $query->where('team_tie_id', $teamTie->id))->count());
    }

    public function test_rubber_game_sides_match_team_entries(): void
    {
        [$teamTie] = $this->createScheduledTeamTie();

        foreach ($teamTie->teamTieGames as $teamTieGame) {
            $game = $teamTieGame->game;

            $this->assertSame((int) $teamTie->entry1_id, (int) $game->entry1_id);
            $this->assertSame((int) $teamTie->entry2_id, (int) $game->entry2_id);
        }
    }

    public function test_rubber_games_have_null_group_and_bracket_metadata(): void
    {
        [$teamTie] = $this->createScheduledTeamTie();

        foreach ($teamTie->teamTieGames as $teamTieGame) {
            $game = $teamTieGame->game;

            $this->assertNull($game->group_id);
            $this->assertNull($game->bracket_id);
            $this->assertNull($game->round);
            $this->assertNull($game->group_round);
            $this->assertNull($game->group_match);
        }
    }

    public function test_rubber_games_do_not_use_player_entries(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTie();

        foreach ($teamTie->teamTieGames as $teamTieGame) {
            $game = $teamTieGame->game;

            $this->assertContains((int) $game->entry1_id, [(int) $entries[0]->id, (int) $entries[1]->id]);
            $this->assertContains((int) $game->entry2_id, [(int) $entries[0]->id, (int) $entries[1]->id]);
            $this->assertNotSame((int) $game->entry1_id, (int) $game->entry2_id);
        }
    }

    public function test_singles_lineup_accepts_one_player_per_side(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTie();
        $singlesGame = $teamTie->teamTieGames()->where('slot_order', 1)->firstOrFail();

        $entry1PlayerId = $this->firstPlayerId($entries[0]);
        $entry2PlayerId = $this->firstPlayerId($entries[1]);

        $this->tournamentContext()
            ->setTeamTieGameLineup($singlesGame, [
                'entry1_player_ids' => [$entry1PlayerId],
                'entry2_player_ids' => [$entry2PlayerId],
            ])
            ->assertOk()
            ->assertJsonPath('data.lineup_complete', true);
    }

    public function test_doubles_lineup_accepts_two_players_per_side(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTie();
        $doublesGame = $teamTie->teamTieGames()->where('slot_order', 3)->firstOrFail();

        $this->tournamentContext()
            ->setTeamTieGameLineup($doublesGame, [
                'entry1_player_ids' => $this->playerIds($entries[0], 2),
                'entry2_player_ids' => $this->playerIds($entries[1], 2),
            ])
            ->assertOk()
            ->assertJsonPath('data.lineup_complete', true);
    }

    public function test_wrong_roster_player_is_rejected(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTie();
        $singlesGame = $teamTie->teamTieGames()->where('slot_order', 1)->firstOrFail();

        $rivalPlayerId = $this->firstPlayerId($entries[1]);

        $this->tournamentContext()
            ->setTeamTieGameLineup($singlesGame, [
                'entry1_player_ids' => [$rivalPlayerId],
                'entry2_player_ids' => [$rivalPlayerId],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['entry1_player_ids']);
    }

    public function test_duplicate_player_in_same_side_is_rejected(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTie();
        $doublesGame = $teamTie->teamTieGames()->where('slot_order', 3)->firstOrFail();
        $playerId = $this->firstPlayerId($entries[0]);

        $this->tournamentContext()
            ->setTeamTieGameLineup($doublesGame, [
                'entry1_player_ids' => [$playerId, $playerId],
                'entry2_player_ids' => $this->playerIds($entries[1], 2),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['entry1_player_ids.0']);
    }

    public function test_invalid_cardinality_is_rejected(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTie();
        $singlesGame = $teamTie->teamTieGames()->where('slot_order', 1)->firstOrFail();

        $this->tournamentContext()
            ->setTeamTieGameLineup($singlesGame, [
                'entry1_player_ids' => $this->playerIds($entries[0], 2),
                'entry2_player_ids' => [$this->firstPlayerId($entries[1])],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['entry1_player_ids']);
    }

    public function test_inactive_entry_is_rejected_for_lineup(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTie();
        $singlesGame = $teamTie->teamTieGames()->where('slot_order', 1)->firstOrFail();

        $entries[0]->update(['status' => CompetitionEntryStatus::Withdrawn]);

        $this->tournamentContext()
            ->setTeamTieGameLineup($singlesGame, [
                'entry1_player_ids' => [$this->firstPlayerId($entries[0])],
                'entry2_player_ids' => [$this->firstPlayerId($entries[1])],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['entry1_player_ids']);
    }

    public function test_inactive_player_is_rejected_for_lineup(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTie();
        $singlesGame = $teamTie->teamTieGames()->where('slot_order', 1)->firstOrFail();
        $playerId = $this->firstPlayerId($entries[0]);

        Player::query()->whereKey($playerId)->update(['active' => false]);

        $this->tournamentContext()
            ->setTeamTieGameLineup($singlesGame, [
                'entry1_player_ids' => [$playerId],
                'entry2_player_ids' => [$this->firstPlayerId($entries[1])],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['entry1_player_ids']);
    }

    public function test_lineup_is_editable_while_game_pending_without_sets(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTie();
        $singlesGame = $teamTie->teamTieGames()->where('slot_order', 1)->firstOrFail();
        $context = $this->tournamentContext();

        $context->setTeamTieGameLineup($singlesGame, [
            'entry1_player_ids' => [$this->firstPlayerId($entries[0])],
            'entry2_player_ids' => [$this->firstPlayerId($entries[1])],
        ])->assertOk();

        $alternatePlayerId = $this->playerIds($entries[0], 2)[1];

        $context->setTeamTieGameLineup($singlesGame->fresh(), [
            'entry1_player_ids' => [$alternatePlayerId],
            'entry2_player_ids' => [$this->firstPlayerId($entries[1])],
        ])->assertOk();
    }

    public function test_lineup_is_locked_after_first_set(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTie();
        $singlesGame = $teamTie->teamTieGames()->where('slot_order', 1)->firstOrFail();
        $context = $this->tournamentContext();

        $context->setTeamTieGameLineup($singlesGame, [
            'entry1_player_ids' => [$this->firstPlayerId($entries[0])],
            'entry2_player_ids' => [$this->firstPlayerId($entries[1])],
        ])->assertOk();

        $context->recordSet($singlesGame->game, 1, 11, 5)->assertOk();

        $context->setTeamTieGameLineup($singlesGame->fresh(), [
            'entry1_player_ids' => [$this->playerIds($entries[0], 2)[1]],
            'entry2_player_ids' => [$this->firstPlayerId($entries[1])],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['game']);
    }

    public function test_team_tie_show_includes_team_tie_games(): void
    {
        [$teamTie] = $this->createScheduledTeamTie();

        $this->tournamentContext()
            ->showTeamTie($teamTie)
            ->assertOk()
            ->assertJsonCount(5, 'data.team_tie_games')
            ->assertJsonPath('data.score.entry1', 0)
            ->assertJsonPath('data.score.entry2', 0)
            ->assertJsonPath('data.rubbers_total', 5);
    }

    public function test_group_team_tie_index_includes_summary_without_full_games(): void
    {
        [$teamTie] = $this->createScheduledTeamTie();

        $this->tournamentContext()
            ->listGroupTeamTies($teamTie->group)
            ->assertOk()
            ->assertJsonPath('data.0.rubbers_total', 5)
            ->assertJsonPath('data.0.rubbers_with_lineup', 0)
            ->assertJsonMissingPath('data.0.team_tie_games');
    }

    public function test_partial_score_updates_when_rubber_finishes(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTie();
        $context = $this->tournamentContext();
        $singlesGame = $teamTie->teamTieGames()->where('slot_order', 1)->firstOrFail();

        $context->setTeamTieGameLineup($singlesGame, [
            'entry1_player_ids' => [$this->firstPlayerId($entries[0])],
            'entry2_player_ids' => [$this->firstPlayerId($entries[1])],
        ])->assertOk();

        $context->finishGameByEntryViaApi($singlesGame->game, (int) $teamTie->entry1_id)->assertOk();

        $context->showTeamTie($teamTie->fresh())
            ->assertOk()
            ->assertJsonPath('data.score.entry1', 1)
            ->assertJsonPath('data.score.entry2', 0)
            ->assertJsonPath('data.score.rubbers_finished', 1);
    }

    public function test_finished_rubber_winner_is_team_entry(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTie();
        $context = $this->tournamentContext();
        $singlesGame = $teamTie->teamTieGames()->where('slot_order', 1)->firstOrFail();

        $context->setTeamTieGameLineup($singlesGame, [
            'entry1_player_ids' => [$this->firstPlayerId($entries[0])],
            'entry2_player_ids' => [$this->firstPlayerId($entries[1])],
        ])->assertOk();

        $context->finishGameByEntryViaApi($singlesGame->game, (int) $teamTie->entry1_id)->assertOk();

        $game = $singlesGame->game->fresh();
        $this->assertSame(GameStatus::Finished, $game->status);
        $this->assertSame((int) $teamTie->entry1_id, (int) $game->winner_entry_id);
    }

    public function test_competition_game_index_excludes_rubbers(): void
    {
        [, , $competition] = $this->createScheduledTeamTie();
        $teamTie = TeamTie::query()->where('competition_id', $competition->id)->firstOrFail();

        $this->tournamentContext()
            ->listCompetitionGames($competition)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_game_show_remains_accessible_for_rubber(): void
    {
        [$teamTie] = $this->createScheduledTeamTie();
        $game = $teamTie->teamTieGames()->firstOrFail()->game;

        $this->getJson($this->tournamentContext()->apiUrl("games/{$game->id}"))
            ->assertOk()
            ->assertJsonPath('data.id', $game->id)
            ->assertJsonPath('team_tie_game.id', $teamTie->teamTieGames()->firstOrFail()->id);
    }

    public function test_correction_updates_derived_score(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTie();
        $context = $this->tournamentContext();
        $singlesGame = $teamTie->teamTieGames()->where('slot_order', 1)->firstOrFail();

        $context->setTeamTieGameLineup($singlesGame, [
            'entry1_player_ids' => [$this->firstPlayerId($entries[0])],
            'entry2_player_ids' => [$this->firstPlayerId($entries[1])],
        ])->assertOk();

        $context->finishGameByEntryViaApi($singlesGame->game, (int) $teamTie->entry1_id)->assertOk();

        $context->correctResult($singlesGame->game->fresh(), 'Error de carga', [
            ['player1_score' => 5, 'player2_score' => 11],
        ])->assertOk();

        $context->showTeamTie($teamTie->fresh())
            ->assertOk()
            ->assertJsonPath('data.score.entry1', 0)
            ->assertJsonPath('data.score.entry2', 1);
    }

    public function test_regeneration_deletes_team_tie_games_and_rubber_games(): void
    {
        [, , $competition] = $this->createScheduledTeamTie();
        $context = $this->tournamentContext();
        $oldRubberGameIds = Game::query()->whereHas('teamTieGame')->pluck('id')->all();

        $context->regenerateRandomGroups($competition, 1)->assertCreated();

        foreach ($oldRubberGameIds as $gameId) {
            $this->assertNull(Game::query()->find($gameId));
        }

        $this->assertGreaterThan(0, TeamTieGame::query()->count());
        $this->assertSame(
            TeamTieGame::query()->count(),
            Game::query()->whereHas('teamTieGame')->count(),
        );
    }

    public function test_regeneration_is_blocked_when_lineup_exists(): void
    {
        [$teamTie, $entries, $competition] = $this->createScheduledTeamTie();
        $context = $this->tournamentContext();
        $singlesGame = $teamTie->teamTieGames()->where('slot_order', 1)->firstOrFail();

        $context->setTeamTieGameLineup($singlesGame, [
            'entry1_player_ids' => [$this->firstPlayerId($entries[0])],
            'entry2_player_ids' => [$this->firstPlayerId($entries[1])],
        ])->assertOk();

        $context->regenerateRandomGroups($competition, 1)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition']);
    }

    public function test_regeneration_is_blocked_when_rubber_has_sets(): void
    {
        [$teamTie, $entries, $competition] = $this->createScheduledTeamTie();
        $context = $this->tournamentContext();
        $singlesGame = $teamTie->teamTieGames()->where('slot_order', 1)->firstOrFail();

        $context->setTeamTieGameLineup($singlesGame, [
            'entry1_player_ids' => [$this->firstPlayerId($entries[0])],
            'entry2_player_ids' => [$this->firstPlayerId($entries[1])],
        ])->assertOk();

        $context->recordSet($singlesGame->game, 1, 11, 5)->assertOk();

        $context->regenerateRandomGroups($competition, 1)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition']);
    }

    public function test_delete_manual_game_blocks_rubber(): void
    {
        [$teamTie] = $this->createScheduledTeamTie();
        $game = $teamTie->teamTieGames()->firstOrFail()->game;

        $this->deleteJson($this->tournamentContext()->apiUrl("games/{$game->id}"), [], $this->authHeaders(['organizer']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['game']);
    }

    public function test_lineup_audit_is_recorded(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTie();
        $singlesGame = $teamTie->teamTieGames()->where('slot_order', 1)->firstOrFail();

        $this->tournamentContext()
            ->setTeamTieGameLineup($singlesGame, [
                'entry1_player_ids' => [$this->firstPlayerId($entries[0])],
                'entry2_player_ids' => [$this->firstPlayerId($entries[1])],
            ])
            ->assertOk();

        $activity = Activity::query()->latest('id')->first();

        $this->assertSame(AuditAction::TEAM_TIE_GAME_LINEUP_UPDATED->value, $activity->description);
        $this->assertSame($teamTie->id, data_get($activity->properties, 'context.team_tie_id'));
    }

    /**
     * @return array{0: TeamTie, 1: list<\App\Models\CompetitionEntry>, 2: \App\Models\Competition}
     */
    private function createScheduledTeamTie(): array
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $entries = $context->registerTeams($competition, 2, 4);
        $group = $context->createGroupWithEntries($competition, $entries);
        $context->generateTeamRoundRobin($group)->assertCreated();

        $teamTie = TeamTie::query()->where('group_id', $group->id)->firstOrFail();
        $teamTie->load('teamTieGames.game');

        return [$teamTie, $entries, $competition];
    }

    private function firstPlayerId(\App\Models\CompetitionEntry $entry): int
    {
        return (int) CompetitionEntryMember::query()
            ->where('competition_entry_id', $entry->id)
            ->orderBy('member_order')
            ->value('player_id');
    }

    /**
     * @return list<int>
     */
    private function playerIds(\App\Models\CompetitionEntry $entry, int $count): array
    {
        return CompetitionEntryMember::query()
            ->where('competition_entry_id', $entry->id)
            ->orderBy('member_order')
            ->limit($count)
            ->pluck('player_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
