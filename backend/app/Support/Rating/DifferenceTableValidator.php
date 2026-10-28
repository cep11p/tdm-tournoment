<?php

namespace App\Support\Rating;

final class DifferenceTableValidator
{
    /**
     * @var list<string>
     */
    private const ALLOWED_KEYS = ['min', 'max', 'favorite_win', 'underdog_win'];

    /**
     * @return array<string, list<string>>
     */
    public static function errors(mixed $rules): array
    {
        if ($rules === [] || $rules === null) {
            return [
                'rules' => ['La tabla de diferencias no puede estar vacía.'],
            ];
        }

        if (! is_array($rules) || ! array_is_list($rules)) {
            return [
                'rules' => ['La tabla de diferencias no es válida.'],
            ];
        }

        $errors = [];
        $count = count($rules);

        foreach ($rules as $index => $range) {
            if (! is_array($range) || array_is_list($range)) {
                $errors["rules.{$index}"][] = 'El rango no es válido.';

                continue;
            }

            if (array_diff(array_keys($range), self::ALLOWED_KEYS) !== []) {
                $errors["rules.{$index}"][] = 'El rango incluye datos que no forman parte de la tabla.';
            }

            $isLast = $index === $count - 1;
            $min = $range['min'] ?? null;
            $max = array_key_exists('max', $range) ? $range['max'] : false;

            if (! self::isNonNegativeInt($min)) {
                $errors["rules.{$index}.min"][] = 'El límite inferior debe ser un entero no negativo.';
            } elseif ($index === 0 && $min !== 0) {
                $errors['rules.0.min'][] = 'El primer rango debe comenzar en 0.';
            }

            if ($isLast) {
                if ($max !== null) {
                    $errors["rules.{$index}.max"][] = 'El último rango no debe tener límite superior.';
                }
            } elseif ($max === null || $max === false) {
                $errors["rules.{$index}.max"][] = 'Solo el último rango puede no tener límite superior.';
            } elseif (! self::isNonNegativeInt($max)) {
                $errors["rules.{$index}.max"][] = 'El límite superior debe ser un entero no negativo.';
            } elseif (is_int($min) && $max < $min) {
                $errors["rules.{$index}.max"][] = 'El límite superior no puede ser menor que el inferior.';
            }

            if (! self::isNonNegativeInt($range['favorite_win'] ?? null)) {
                $errors["rules.{$index}.favorite_win"][] = 'La victoria del favorito debe ser un entero no negativo.';
            }

            if (! self::isNonNegativeInt($range['underdog_win'] ?? null)) {
                $errors["rules.{$index}.underdog_win"][] = 'La victoria del jugador con menor rating debe ser un entero no negativo.';
            }
        }

        for ($index = 1; $index < $count; $index++) {
            $previous = $rules[$index - 1];
            $current = $rules[$index];

            if (! is_array($previous) || array_is_list($previous) || ! is_array($current) || array_is_list($current)) {
                continue;
            }

            $previousMax = $previous['max'] ?? null;
            $currentMin = $current['min'] ?? null;

            if (! is_int($previousMax) || ! is_int($currentMin)) {
                continue;
            }

            $expected = $previousMax + 1;

            if ($currentMin < $expected) {
                $errors["rules.{$index}.min"][] = 'Los rangos no pueden solaparse.';
            } elseif ($currentMin > $expected) {
                $errors["rules.{$index}.min"][] = 'Los rangos deben ser contiguos, sin huecos.';
            }
        }

        return $errors;
    }

    private static function isNonNegativeInt(mixed $value): bool
    {
        return is_int($value) && $value >= 0;
    }
}
