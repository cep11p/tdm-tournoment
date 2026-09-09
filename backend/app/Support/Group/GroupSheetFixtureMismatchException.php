<?php

namespace App\Support\Group;

use InvalidArgumentException;

final class GroupSheetFixtureMismatchException extends InvalidArgumentException
{
    public static function forGroup(): self
    {
        return new self(
            'Los partidos del grupo no coinciden con el round robin esperado para la planilla.',
        );
    }
}
