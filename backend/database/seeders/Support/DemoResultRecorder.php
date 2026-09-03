<?php

namespace Database\Seeders\Support;

use App\Actions\Bracket\CreateBracketKnockoutAction;
use App\Actions\Bracket\GenerateBracketNextRoundAction;
use App\Actions\Game\RecordGameSetAction;
use App\Actions\TeamTie\SetTeamTieGameLineupAction;
use App\Enums\GameStatus;
use App\Enums\TeamTieModality;
use App\Models\Bracket;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\Game;
use App\Models\Group;
use App\Models\TeamTie;
use App\Models\TeamTieGame;
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

    /**
     * Copa 5 roster indexes (0-based member_order) per slot.
     *
     * @var array<int, list<int>>
     */
    private const COPA_5_LINEUP_INDEXES = [
        1 => [0],
        2 => [1],
        3 => [0, 2],
        4 => [3],
        5 => [2],
    ];

    public function __construct(
        private readonly RecordGameSetAction $recordGameSet,
        private readonly SetTeamTieGameLineupAction $setTeamTieGameLineup,
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

        if ($game->status === GameStatus::Finished || $game->status === GameStatus::NotNeeded || $game->is_bye) {
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

    public function lineupTeamTieGame(TeamTieGame $teamTieGame): void
    {
        $teamTieGame->loadMissing(['teamTie.entry1.members', 'teamTie.entry2.members', 'game', 'members']);

        $game = $teamTieGame->game;

        if ($game === null || $game->status !== GameStatus::Pending) {
            return;
        }

        if ($teamTieGame->isLineupComplete()) {
            return;
        }

        $teamTie = $teamTieGame->teamTie;

        if ($teamTie === null || $teamTie->entry1 === null || $teamTie->entry2 === null) {
            throw new \RuntimeException('El enfrentamiento no tiene ambos equipos asignados.');
        }

        $indexes = self::COPA_5_LINEUP_INDEXES[(int) $teamTieGame->slot_order] ?? null;

        if ($indexes === null) {
            throw new \RuntimeException(sprintf(
                'No hay patrón de lineup demo para el slot %d.',
                $teamTieGame->slot_order,
            ));
        }

        $required = $teamTieGame->modality === TeamTieModality::Doubles ? 2 : 1;

        if (count($indexes) !== $required) {
            throw new \RuntimeException(sprintf(
                'El patrón de lineup del slot %d no coincide con la modalidad %s.',
                $teamTieGame->slot_order,
                $teamTieGame->modality->value,
            ));
        }

        ($this->setTeamTieGameLineup)($teamTieGame, [
            'entry1_player_ids' => $this->rosterPlayerIdsForIndexes($teamTie->entry1, $indexes),
            'entry2_player_ids' => $this->rosterPlayerIdsForIndexes($teamTie->entry2, $indexes),
        ]);
    }

    public function lineupTeamTie(TeamTie $teamTie): void
    {
        $teamTie->loadMissing(['teamTieGames.game', 'teamTieGames.members']);

        foreach ($teamTie->teamTieGames as $teamTieGame) {
            $this->lineupTeamTieGame($teamTieGame);
        }
    }

    /**
     * @param  array<int, int>  $slotWinnerEntryIds  slot_order => winner competition_entry_id
     */
    public function finishTeamTieSlots(TeamTie $teamTie, array $slotWinnerEntryIds): void
    {
        $this->lineupTeamTie($teamTie);

        foreach ($slotWinnerEntryIds as $slotOrder => $winnerEntryId) {
            $this->finishTeamTieRubber($teamTie->fresh(), (int) $slotOrder, (int) $winnerEntryId);
        }
    }

    public function finishTeamTieByBetterSeed(TeamTie $teamTie): void
    {
        $teamTie->loadMissing(['entry1', 'entry2']);

        if ($teamTie->entry1 === null || $teamTie->entry2 === null) {
            throw new \RuntimeException('El enfrentamiento no tiene ambos equipos asignados.');
        }

        $winnerEntryId = $this->betterEntry($teamTie->entry1, $teamTie->entry2)->id;

        $this->finishTeamTieSlots($teamTie, [
            1 => $winnerEntryId,
            2 => $winnerEntryId,
            3 => $winnerEntryId,
        ]);
    }

    /**
     * @param  array<int, int>  $slotWinnerEntryIds  slot_order => winner competition_entry_id
     */
    public function finishTeamTiePartial(TeamTie $teamTie, array $slotWinnerEntryIds): void
    {
        $this->finishTeamTieSlots($teamTie, $slotWinnerEntryIds);
    }

    public function finishTeamTieRubber(TeamTie $teamTie, int $slotOrder, int $winnerEntryId, int $scoreVariantIndex = 0): void
    {
        $rubber = $this->rubberAt($teamTie, $slotOrder);
        $game = $rubber->game?->fresh() ?? $rubber->game()->first();

        if ($game === null) {
            throw new \RuntimeException(sprintf(
                'El slot %d del enfrentamiento %d no tiene partido asociado.',
                $slotOrder,
                $teamTie->id,
            ));
        }

        $this->finishGame($game, $winnerEntryId, $scoreVariantIndex);
    }

    /**
     * @param  Collection<int, TeamTie>  $teamTies
     */
    public function findTeamTieBetweenEntries(Collection $teamTies, int $entry1Id, int $entry2Id): TeamTie
    {
        $teamTie = $teamTies->first(
            fn (TeamTie $candidate): bool => (
                (int) $candidate->entry1_id === $entry1Id && (int) $candidate->entry2_id === $entry2Id
            ) || (
                (int) $candidate->entry1_id === $entry2Id && (int) $candidate->entry2_id === $entry1Id
            )
        );

        if ($teamTie === null) {
            throw new \RuntimeException(sprintf(
                'No se encontró enfrentamiento entre las participaciones %d y %d.',
                $entry1Id,
                $entry2Id,
            ));
        }

        return $teamTie;
    }

    private function rubberAt(TeamTie $teamTie, int $slotOrder): TeamTieGame
    {
        $teamTie->loadMissing(['teamTieGames.game']);

        $rubber = $teamTie->teamTieGames->first(
            fn (TeamTieGame $candidate): bool => (int) $candidate->slot_order === $slotOrder,
        );

        if ($rubber === null) {
            throw new \RuntimeException(sprintf(
                'No se encontró el slot %d en el enfrentamiento %d.',
                $slotOrder,
                $teamTie->id,
            ));
        }

        return $rubber;
    }

    /**
     * @param  list<int>  $indexes
     * @return list<int>
     */
    private function rosterPlayerIdsForIndexes(CompetitionEntry $entry, array $indexes): array
    {
        $playerIds = $entry->members()
            ->orderBy('member_order')
            ->pluck('player_id')
            ->map(fn ($playerId): int => (int) $playerId)
            ->values()
            ->all();

        $selected = [];

        foreach ($indexes as $index) {
            if (! isset($playerIds[$index])) {
                throw new \RuntimeException(sprintf(
                    'El roster de la participación %d no tiene jugador en member_order %d.',
                    $entry->id,
                    $index + 1,
                ));
            }

            $selected[] = $playerIds[$index];
        }

        return $selected;
    }
}
