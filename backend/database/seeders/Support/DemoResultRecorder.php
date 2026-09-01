<?php

namespace Database\Seeders\Support;

use App\Actions\Bracket\CreateBracketKnockoutAction;
use App\Actions\Bracket\GenerateBracketNextRoundAction;
use App\Actions\Game\RecordGameSetAction;
use App\Enums\BracketGamePurpose;
use App\Enums\GameStatus;
use App\Models\Bracket;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\Game;
use App\Models\Group;
use App\Support\Bracket\BracketPodiumSupport;
use Illuminate\Support\Collection;

final class DemoResultRecorder
{
    /**
     * @var array<int, array<int, array{0: int, 1: int}>>
     */
    private const SET_TEMPLATES = [
        [[11, 7], [11, 8]],
        [[11, 5], [11, 9]],
        [[11, 6], [11, 4]],
    ];

    /**
     * Balanced four-set pattern used to produce unresolved triple ties (BO3).
     *
     * @var array<int, array{0: int, 1: int}>
     */
    public const BALANCED_FOUR_SET_PATTERN = [
        [11, 9],
        [11, 9],
        [9, 11],
        [11, 9],
    ];

    public function __construct(
        private readonly RecordGameSetAction $recordGameSet,
    ) {}

    public function entrySeed(CompetitionEntry $entry): int
    {
        $entry->loadMissing('members.player');

        $seeds = $entry->members
            ->map(fn ($member): int => DemoPlayerCatalog::seedForPlayer($member->player))
            ->all();

        if ($seeds === []) {
            throw new \RuntimeException('La participación no tiene miembros para calcular seed demo.');
        }

        return min($seeds);
    }

    public function betterEntry(CompetitionEntry $left, CompetitionEntry $right): CompetitionEntry
    {
        return $this->entrySeed($left) <= $this->entrySeed($right) ? $left : $right;
    }

    public function winnerEntryIdForGame(Game $game): int
    {
        $game->loadMissing(['entry1', 'entry2']);

        if ($game->entry1 === null || $game->entry2 === null) {
            throw new \RuntimeException('El partido no tiene ambas participaciones asignadas.');
        }

        return $this->betterEntry($game->entry1, $game->entry2)->id;
    }

    public function finishGame(Game $game, int $winnerEntryId, int $scoreVariantIndex = 0): void
    {
        $game->refresh();

        if ($game->status === GameStatus::Finished || $game->is_bye) {
            return;
        }

        $game->loadMissing(['entry1', 'entry2', 'competition']);
        $setsToWin = (int) ($game->sets_to_win ?? $game->competition->sets_to_win);
        $template = self::SET_TEMPLATES[$scoreVariantIndex % count(self::SET_TEMPLATES)];
        $winnerIsEntry1 = (int) $game->entry1_id === $winnerEntryId;

        for ($setIndex = 0; $setIndex < $setsToWin; $setIndex++) {
            [$winnerScore, $loserScore] = $template[$setIndex] ?? $template[array_key_last($template)];
            $game = ($this->recordGameSet)($game, [
                'set_number' => $setIndex + 1,
                'player1_score' => $winnerIsEntry1 ? $winnerScore : $loserScore,
                'player2_score' => $winnerIsEntry1 ? $loserScore : $winnerScore,
            ]);
        }
    }

    public function finishGameByBetterSeed(Game $game, int $scoreVariantIndex = 0): void
    {
        $this->finishGame($game, $this->winnerEntryIdForGame($game), $scoreVariantIndex);
    }

    /**
     * @param  array<int, array{0: int, 1: int}>  $setScores
     */
    public function finishGameWithSetScores(Game $game, int $winnerEntryId, array $setScores): void
    {
        $game->refresh();

        if ($game->status === GameStatus::Finished || $game->is_bye) {
            return;
        }

        $game->loadMissing(['entry1', 'entry2']);
        $winnerIsEntry1 = (int) $game->entry1_id === $winnerEntryId;

        foreach ($setScores as $setIndex => [$leftScore, $rightScore]) {
            $game->refresh();

            if ($game->status === GameStatus::Finished) {
                break;
            }

            $player1Score = $winnerIsEntry1 ? $leftScore : $rightScore;
            $player2Score = $winnerIsEntry1 ? $rightScore : $leftScore;

            $game = ($this->recordGameSet)($game, [
                'set_number' => $setIndex + 1,
                'player1_score' => $player1Score,
                'player2_score' => $player2Score,
            ]);
        }
    }

    public function finishAllGroupGamesByBetterSeed(Group $group): void
    {
        $games = $group->games()->orderBy('id')->get();

        foreach ($games as $index => $game) {
            $this->finishGameByBetterSeed($game, $index);
        }
    }

    /**
     * @param  Collection<int, Game>  $games
     */
    public function findGameBetweenEntries(Collection $games, int $entry1Id, int $entry2Id): Game
    {
        $game = $games->first(
            fn (Game $candidate): bool => (
                (int) $candidate->entry1_id === $entry1Id && (int) $candidate->entry2_id === $entry2Id
            ) || (
                (int) $candidate->entry1_id === $entry2Id && (int) $candidate->entry2_id === $entry1Id
            )
        );

        if ($game === null) {
            throw new \RuntimeException(sprintf(
                'No se encontró partido entre las participaciones %d y %d.',
                $entry1Id,
                $entry2Id,
            ));
        }

        return $game;
    }

    public function createBracket(Competition $competition): Bracket
    {
        if ($competition->brackets()->exists()) {
            return $competition->brackets()->firstOrFail();
        }

        return app(CreateBracketKnockoutAction::class)($competition, []);
    }

    public function completeCompetitionBracket(Competition $competition): void
    {
        $bracket = $this->createBracket($competition);

        while (true) {
            $bracket->refresh();
            $currentRound = (int) Game::query()
                ->where('bracket_id', $bracket->id)
                ->mainBracket()
                ->max('bracket_round');

            $currentGames = Game::query()
                ->where('bracket_id', $bracket->id)
                ->mainBracket()
                ->where('bracket_round', $currentRound)
                ->orderBy('bracket_match')
                ->get();

            foreach ($currentGames as $index => $game) {
                if ($game->is_bye || $game->status === GameStatus::Finished) {
                    continue;
                }

                $this->finishGameByBetterSeed($game, $index);
            }

            $final = $currentGames->first(fn (Game $game): bool => $game->round === 'Final');

            if ($final !== null && $final->fresh()->status === GameStatus::Finished) {
                $this->finishThirdPlaceIfNeeded($competition, $bracket->fresh());

                return;
            }

            app(GenerateBracketNextRoundAction::class)($bracket->fresh());
        }
    }

    public function finishThirdPlaceIfNeeded(Competition $competition, Bracket $bracket): void
    {
        $thirdPlaceGame = BracketPodiumSupport::findThirdPlaceGame($bracket);

        if ($thirdPlaceGame === null || $thirdPlaceGame->status === GameStatus::Finished) {
            return;
        }

        $this->finishGameByBetterSeed($thirdPlaceGame);
    }
}
