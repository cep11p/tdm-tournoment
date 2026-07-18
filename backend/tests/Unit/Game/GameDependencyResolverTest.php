<?php

namespace Tests\Unit\Game;

use App\Enums\GameStatus;
use App\Models\Bracket;
use App\Models\Competition;
use App\Models\Game;
use App\Support\Bracket\BracketSupport;
use App\Support\Game\GameDependencyResolver;
use Tests\TestCase;

class GameDependencyResolverTest extends TestCase
{
    private GameDependencyResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(GameDependencyResolver::class);
    }

    public function test_destination_match_number_formula(): void
    {
        $this->assertSame(1, $this->resolver->destinationMatchNumber(1));
        $this->assertSame(1, $this->resolver->destinationMatchNumber(2));
        $this->assertSame(2, $this->resolver->destinationMatchNumber(3));
        $this->assertSame(2, $this->resolver->destinationMatchNumber(4));
    }

    public function test_winner_slot_formula(): void
    {
        $this->assertSame('player1_id', $this->resolver->winnerSlot(1));
        $this->assertSame('player2_id', $this->resolver->winnerSlot(2));
        $this->assertSame('player1_id', $this->resolver->winnerSlot(3));
        $this->assertSame('player2_id', $this->resolver->winnerSlot(4));
    }

    public function test_resolve_maps_quarterfinals_to_semifinals(): void
    {
        $fixture = $this->createBracketFixture(roundOneMatches: 4, roundTwoMatches: 2);

        $expected = [
            1 => ['match' => 1, 'slot' => 'player1_id'],
            2 => ['match' => 1, 'slot' => 'player2_id'],
            3 => ['match' => 2, 'slot' => 'player1_id'],
            4 => ['match' => 2, 'slot' => 'player2_id'],
        ];

        foreach ($expected as $sourceMatch => $expectation) {
            $source = $fixture['roundOneGames'][$sourceMatch - 1]->fresh();
            $dependency = $this->resolver->resolveNextRoundDependency($source);

            $this->assertNotNull($dependency);
            $this->assertSame($expectation['match'], $dependency['destination_match']);
            $this->assertSame($expectation['slot'], $dependency['slot']);
            $this->assertSame($source->winner_id, $dependency['expected_player_id']);
            $this->assertSame(
                $fixture['roundTwoGames'][$expectation['match'] - 1]->id,
                $dependency['game']->id,
            );
        }
    }

    public function test_resolve_returns_null_when_next_round_does_not_exist(): void
    {
        $fixture = $this->createBracketFixture(roundOneMatches: 2, roundTwoMatches: 0);
        $source = $fixture['roundOneGames'][0];

        $this->assertNull($this->resolver->resolveNextRoundDependency($source));
    }

    public function test_has_round_beyond_immediate_is_false_with_only_next_round(): void
    {
        $fixture = $this->createBracketFixture(roundOneMatches: 4, roundTwoMatches: 2);
        $source = $fixture['roundOneGames'][0];

        $this->assertFalse($this->resolver->hasRoundBeyondImmediate($source));
    }

    public function test_has_round_beyond_immediate_is_true_when_extra_round_exists(): void
    {
        $fixture = $this->createBracketFixture(roundOneMatches: 4, roundTwoMatches: 2, roundThreeMatches: 1);
        $source = $fixture['roundOneGames'][0];

        $this->assertTrue($this->resolver->hasRoundBeyondImmediate($source));
    }

    public function test_play_in_round_one_uses_same_formula_for_round_two(): void
    {
        $fixture = $this->createBracketFixture(
            roundOneMatches: 4,
            roundTwoMatches: 2,
            roundOneLabel: BracketSupport::PLAY_IN_ROUND_LABEL,
            roundTwoLabel: 'Cuartos de final',
        );

        $playInGame = $fixture['roundOneGames'][1];
        $dependency = $this->resolver->resolveNextRoundDependency($playInGame);

        $this->assertNotNull($dependency);
        $this->assertSame(1, $dependency['destination_match']);
        $this->assertSame('player2_id', $dependency['slot']);
        $this->assertSame('Cuartos de final', $dependency['game']->round);
    }

    /**
     * @return array{
     *     bracket: Bracket,
     *     roundOneGames: array<int, Game>,
     *     roundTwoGames: array<int, Game>,
     * }
     */
    private function createBracketFixture(
        int $roundOneMatches,
        int $roundTwoMatches,
        int $roundThreeMatches = 0,
        string $roundOneLabel = 'Cuartos de final',
        string $roundTwoLabel = 'Semifinal',
    ): array {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(max(2, $roundOneMatches * 2 + $roundTwoMatches * 2 + $roundThreeMatches * 2));
        $context->registerPlayers($competition, $players);

        $bracket = Bracket::query()->create([
            'competition_id' => $competition->id,
            'name' => 'Llave test',
            'qualifiers_per_group' => 0,
            'bracket_size' => 8,
            'byes_count' => 0,
        ]);

        $roundOneGames = [];

        for ($match = 1; $match <= $roundOneMatches; $match++) {
            $playerOne = $players[($match - 1) * 2];
            $playerTwo = $players[($match - 1) * 2 + 1];

            $roundOneGames[] = Game::query()->create([
                'competition_id' => $competition->id,
                'bracket_id' => $bracket->id,
                'player1_id' => $playerOne->id,
                'player2_id' => $playerTwo->id,
                'winner_id' => $playerOne->id,
                'status' => GameStatus::Finished,
                'finished_at' => now(),
                'round' => $roundOneLabel,
                'bracket_round' => 1,
                'bracket_match' => $match,
                'is_bye' => false,
                'best_of' => 1,
                'sets_to_win' => 1,
            ]);
        }

        $roundTwoGames = [];

        for ($match = 1; $match <= $roundTwoMatches; $match++) {
            $roundTwoGames[] = Game::query()->create([
                'competition_id' => $competition->id,
                'bracket_id' => $bracket->id,
                'player1_id' => $roundOneGames[($match - 1) * 2]->winner_id,
                'player2_id' => $roundOneGames[($match - 1) * 2 + 1]->winner_id,
                'winner_id' => null,
                'status' => GameStatus::Pending,
                'round' => $roundTwoLabel,
                'bracket_round' => 2,
                'bracket_match' => $match,
                'is_bye' => false,
                'best_of' => 1,
                'sets_to_win' => 1,
            ]);
        }

        for ($match = 1; $match <= $roundThreeMatches; $match++) {
            Game::query()->create([
                'competition_id' => $competition->id,
                'bracket_id' => $bracket->id,
                'player1_id' => $players[0]->id,
                'player2_id' => $players[1]->id,
                'winner_id' => null,
                'status' => GameStatus::Pending,
                'round' => 'Final',
                'bracket_round' => 3,
                'bracket_match' => $match,
                'is_bye' => false,
                'best_of' => 1,
                'sets_to_win' => 1,
            ]);
        }

        return [
            'bracket' => $bracket,
            'roundOneGames' => $roundOneGames,
            'roundTwoGames' => $roundTwoGames,
        ];
    }
}
