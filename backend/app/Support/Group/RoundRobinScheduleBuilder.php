<?php

namespace App\Support\Group;

final class RoundRobinScheduleBuilder
{
    /**
     * Build round-robin pairings using the circle (Berger) method.
     *
     * @param  array<int>  $playerIds
     * @return array<int, array<int, array{player1_id: int, player2_id: int}>>
     */
    public function build(array $playerIds): array
    {
        $participants = array_values($playerIds);

        if (count($participants) % 2 !== 0) {
            $participants[] = null;
        }

        $participantCount = count($participants);
        $fixed = array_shift($participants);
        $rotating = $participants;
        $rounds = [];

        for ($roundIndex = 0; $roundIndex < $participantCount - 1; $roundIndex++) {
            $roundLayout = array_merge([$fixed], $rotating);
            $roundPairings = [];

            for ($pairIndex = 0; $pairIndex < intdiv($participantCount, 2); $pairIndex++) {
                $homeId = $roundLayout[$pairIndex];
                $awayId = $roundLayout[$participantCount - 1 - $pairIndex];

                if ($homeId === null || $awayId === null) {
                    continue;
                }

                $roundPairings[] = [
                    'player1_id' => (int) $homeId,
                    'player2_id' => (int) $awayId,
                ];
            }

            $rounds[] = $roundPairings;

            $lastRotating = array_pop($rotating);
            array_unshift($rotating, $lastRotating);
        }

        return $rounds;
    }
}
