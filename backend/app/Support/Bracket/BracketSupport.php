<?php

namespace App\Support\Bracket;

use Illuminate\Validation\ValidationException;

final class BracketSupport
{
    public const MAX_BRACKET_SIZE = 64;

    public const PLAY_IN_ROUND_LABEL = 'Ronda clasificatoria';

    public static function nextPowerOfTwo(int $count): int
    {
        if ($count <= 1) {
            return 2;
        }

        $power = 1;

        while ($power < $count) {
            $power <<= 1;
        }

        return $power;
    }

    /**
     * Posiciones estándar de seeds (1-indexed) en orden de slot.
     *
     * El índice 0 es el lado 1 del partido 1; el 1, el lado 2 del partido 1;
     * y así sucesivamente. Compatible con el avance por ganadores adyacentes.
     *
     * @return list<int>
     */
    public static function seedPositions(int $bracketSize): array
    {
        if ($bracketSize < 2 || $bracketSize > self::MAX_BRACKET_SIZE || ($bracketSize & ($bracketSize - 1)) !== 0) {
            throw ValidationException::withMessages([
                'bracket' => [
                    sprintf('Tamaño de llave inválido para el seeding: %d.', $bracketSize),
                ],
            ]);
        }

        $positions = [1];

        while (count($positions) < $bracketSize) {
            $size = count($positions) * 2;
            $next = [];

            foreach ($positions as $seed) {
                $next[] = $seed;
                $next[] = $size + 1 - $seed;
            }

            $positions = $next;
        }

        return $positions;
    }

    /**
     * Partidos de primera ronda a partir de entry IDs ordenados como seed 1..N.
     * Un seed mayor que N se materializa como BYE en el segundo lado.
     *
     * @param  list<int>  $entryIds
     * @return list<array{bracketMatch: int, entry1Id: int, entry2Id: int|null, isBye: bool}>
     */
    public static function firstRoundSlots(array $entryIds): array
    {
        $participantCount = count($entryIds);

        if ($participantCount < 2) {
            throw ValidationException::withMessages([
                'bracket' => ['Se requieren al menos 2 participantes para generar el cuadro eliminatorio.'],
            ]);
        }

        $bracketSize = self::nextPowerOfTwo($participantCount);

        if ($bracketSize > self::MAX_BRACKET_SIZE) {
            throw ValidationException::withMessages([
                'bracket' => [
                    sprintf(
                        'El cuadro eliminatorio admite hasta %d clasificados. La configuración actual produce %d.',
                        self::MAX_BRACKET_SIZE,
                        $participantCount,
                    ),
                ],
            ]);
        }

        $positions = self::seedPositions($bracketSize);
        $slots = [];

        for ($index = 0; $index < $bracketSize; $index += 2) {
            $entry1Id = self::entryIdForSeed($entryIds, $positions[$index]);
            $entry2Id = self::entryIdForSeed($entryIds, $positions[$index + 1]);

            if ($entry1Id === null) {
                throw ValidationException::withMessages([
                    'bracket' => ['El cuadro eliminatorio no puede comenzar con un BYE en el primer lado del partido.'],
                ]);
            }

            $slots[] = [
                'bracketMatch' => (int) (($index / 2) + 1),
                'entry1Id' => $entry1Id,
                'entry2Id' => $entry2Id,
                'isBye' => $entry2Id === null,
            ];
        }

        return $slots;
    }

    /**
     * @param  list<int>  $entryIds
     */
    private static function entryIdForSeed(array $entryIds, int $seed): ?int
    {
        if ($seed < 1 || $seed > count($entryIds)) {
            return null;
        }

        return $entryIds[$seed - 1];
    }

    public static function roundLabelFor(int $playersInRound): string
    {
        return match ($playersInRound) {
            32 => '16avos de final',
            16 => '8vos de final',
            8 => 'Cuartos de final',
            4 => 'Semifinal',
            2 => 'Final',
            default => throw ValidationException::withMessages([
                'bracket' => [
                    sprintf('Cantidad de jugadores inválida para la ronda: %d.', $playersInRound),
                ],
            ]),
        };
    }
}
