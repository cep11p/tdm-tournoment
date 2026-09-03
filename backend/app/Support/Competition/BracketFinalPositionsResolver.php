<?php

namespace App\Support\Competition;

use App\Data\Competition\CompetitionFinalStandingData;
use App\Enums\CompetitionFinalStandingSource;
use App\Enums\ThirdPlaceMode;
use App\Support\Bracket\BracketSupport;
use Illuminate\Validation\ValidationException;

final class BracketFinalPositionsResolver
{
    /**
     * @param  list<CompetitionFinalMatchSnapshot>  $snapshots
     * @return list<CompetitionFinalStandingData>
     */
    public function resolve(
        int $bracketSize,
        ThirdPlaceMode $thirdPlaceMode,
        array $snapshots,
    ): array {
        if ($bracketSize < 2 || ($bracketSize & ($bracketSize - 1)) !== 0) {
            throw ValidationException::withMessages([
                'competition' => ['El cuadro eliminatorio no tiene un tamaño válido para consolidar la clasificación.'],
            ]);
        }

        $final = $this->findFinal($snapshots);

        if ($final === null || ! $final->isFinishedWithWinner() || $final->loserEntryId() === null) {
            throw ValidationException::withMessages([
                'competition' => ['No se puede consolidar la clasificación final porque la competencia no está finalizada.'],
            ]);
        }

        $placements = [];
        $placed = [];

        $this->addPlacement(
            $placements,
            $placed,
            (int) $final->winnerEntryId,
            1,
            1,
            CompetitionFinalStandingSource::Final,
        );
        $this->addPlacement(
            $placements,
            $placed,
            (int) $final->loserEntryId(),
            2,
            2,
            CompetitionFinalStandingSource::Final,
        );

        $this->placeThirdAndFourth($placements, $placed, $bracketSize, $thirdPlaceMode, $snapshots);

        $this->placeEarlierRoundLosers($placements, $placed, $bracketSize, $snapshots);

        return array_values($placements);
    }

    /**
     * @param  list<CompetitionFinalMatchSnapshot>  $snapshots
     */
    private function findFinal(array $snapshots): ?CompetitionFinalMatchSnapshot
    {
        foreach ($snapshots as $snapshot) {
            if ($snapshot->isMain() && $snapshot->round === 'Final') {
                return $snapshot;
            }
        }

        return null;
    }

    /**
     * @param  array<int, CompetitionFinalStandingData>  $placements
     * @param  array<int, true>  $placed
     * @param  list<CompetitionFinalMatchSnapshot>  $snapshots
     */
    private function placeThirdAndFourth(
        array &$placements,
        array &$placed,
        int $bracketSize,
        ThirdPlaceMode $thirdPlaceMode,
        array $snapshots,
    ): void {
        if ($bracketSize < 4) {
            return;
        }

        $semifinalLosers = $this->realSemifinalLosers($bracketSize, $snapshots);

        if ($thirdPlaceMode === ThirdPlaceMode::Playoff) {
            if ($semifinalLosers === []) {
                return;
            }

            $thirdPlace = $this->findThirdPlace($snapshots);

            if (
                $thirdPlace === null
                || ! $thirdPlace->isFinishedWithWinner()
                || $thirdPlace->loserEntryId() === null
            ) {
                throw ValidationException::withMessages([
                    'competition' => ['No se puede consolidar la clasificación final porque la competencia no está finalizada.'],
                ]);
            }

            $this->addPlacement(
                $placements,
                $placed,
                (int) $thirdPlace->winnerEntryId,
                3,
                3,
                CompetitionFinalStandingSource::ThirdPlacePlayoff,
            );
            $this->addPlacement(
                $placements,
                $placed,
                (int) $thirdPlace->loserEntryId(),
                4,
                4,
                CompetitionFinalStandingSource::ThirdPlacePlayoff,
            );

            return;
        }

        if ($semifinalLosers === []) {
            return;
        }

        foreach ($semifinalLosers as $loserEntryId) {
            $this->addPlacement(
                $placements,
                $placed,
                $loserEntryId,
                3,
                4,
                CompetitionFinalStandingSource::Semifinal,
            );
        }
    }

    /**
     * @param  array<int, CompetitionFinalStandingData>  $placements
     * @param  array<int, true>  $placed
     * @param  list<CompetitionFinalMatchSnapshot>  $snapshots
     */
    private function placeEarlierRoundLosers(
        array &$placements,
        array &$placed,
        int $bracketSize,
        array $snapshots,
    ): void {
        $finalRound = (int) log($bracketSize, 2);
        $rounds = [];

        foreach ($snapshots as $snapshot) {
            if (! $snapshot->isMain() || $snapshot->bracketRound === null) {
                continue;
            }

            $round = (int) $snapshot->bracketRound;

            if ($round === $finalRound) {
                continue;
            }

            $rounds[$round][] = $snapshot;
        }

        ksort($rounds);

        foreach ($rounds as $round => $roundSnapshots) {
            $playersInRound = $this->playersInRound($bracketSize, $round);
            $position = intdiv($playersInRound, 2) + 1;
            $positionRangeEnd = $playersInRound;
            $source = $this->sourceForRound($playersInRound, $roundSnapshots[0]->round ?? null);

            foreach ($roundSnapshots as $snapshot) {
                if ($snapshot->isBye) {
                    continue;
                }

                if (! $snapshot->isFinishedWithWinner()) {
                    throw ValidationException::withMessages([
                        'competition' => ['No se puede consolidar la clasificación final porque la competencia no está finalizada.'],
                    ]);
                }

                $loserEntryId = $snapshot->loserEntryId();

                if ($loserEntryId === null || isset($placed[$loserEntryId])) {
                    continue;
                }

                $this->addPlacement(
                    $placements,
                    $placed,
                    $loserEntryId,
                    $position,
                    $positionRangeEnd,
                    $source,
                );
            }
        }
    }

    /**
     * @param  list<CompetitionFinalMatchSnapshot>  $snapshots
     * @return list<int>
     */
    private function realSemifinalLosers(int $bracketSize, array $snapshots): array
    {
        if ($bracketSize < 4) {
            return [];
        }

        $semifinalRound = (int) log($bracketSize, 2) - 1;
        $semifinals = [];

        foreach ($snapshots as $snapshot) {
            if (! $snapshot->isMain() || (int) $snapshot->bracketRound !== $semifinalRound) {
                continue;
            }

            $semifinals[] = $snapshot;
        }

        if (count($semifinals) !== 2) {
            return [];
        }

        $losers = [];

        foreach ($semifinals as $snapshot) {
            if ($snapshot->isBye || $snapshot->entry1Id === null || $snapshot->entry2Id === null) {
                return [];
            }

            if (! $snapshot->isFinishedWithWinner()) {
                return [];
            }

            $loserEntryId = $snapshot->loserEntryId();

            if ($loserEntryId === null) {
                return [];
            }

            $losers[] = $loserEntryId;
        }

        if (count(array_unique($losers)) !== 2) {
            return [];
        }

        return $losers;
    }

    /**
     * @param  list<CompetitionFinalMatchSnapshot>  $snapshots
     */
    private function findThirdPlace(array $snapshots): ?CompetitionFinalMatchSnapshot
    {
        foreach ($snapshots as $snapshot) {
            if ($snapshot->isThirdPlace()) {
                return $snapshot;
            }
        }

        return null;
    }

    /**
     * @param  array<int, CompetitionFinalStandingData>  $placements
     * @param  array<int, true>  $placed
     */
    private function addPlacement(
        array &$placements,
        array &$placed,
        int $competitionEntryId,
        int $position,
        int $positionRangeEnd,
        CompetitionFinalStandingSource $source,
    ): void {
        if (isset($placed[$competitionEntryId])) {
            throw ValidationException::withMessages([
                'competition' => ['La clasificación final contiene participaciones duplicadas.'],
            ]);
        }

        $placed[$competitionEntryId] = true;
        $placements[$competitionEntryId] = new CompetitionFinalStandingData(
            competitionEntryId: $competitionEntryId,
            position: $position,
            positionRangeEnd: $positionRangeEnd,
            source: $source,
            displayNameSnapshot: '',
        );
    }

    private function playersInRound(int $bracketSize, int $round): int
    {
        $playersInRound = (int) ($bracketSize / (2 ** ($round - 1)));

        if ($playersInRound < 2) {
            throw ValidationException::withMessages([
                'competition' => ['El cuadro eliminatorio no tiene un tamaño válido para consolidar la clasificación.'],
            ]);
        }

        return $playersInRound;
    }

    private function sourceForRound(int $playersInRound, ?string $roundLabel): CompetitionFinalStandingSource
    {
        if ($roundLabel === BracketSupport::PLAY_IN_ROUND_LABEL) {
            return CompetitionFinalStandingSource::PlayIn;
        }

        return match ($playersInRound) {
            4 => CompetitionFinalStandingSource::Semifinal,
            8 => CompetitionFinalStandingSource::Quarterfinal,
            16 => CompetitionFinalStandingSource::RoundOf16,
            32 => CompetitionFinalStandingSource::RoundOf32,
            default => throw ValidationException::withMessages([
                'competition' => [
                    sprintf('No se puede asignar origen de clasificación para una ronda de %d participantes.', $playersInRound),
                ],
            ]),
        };
    }
}
