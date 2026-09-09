<?php

namespace App\Support\Group;

use InvalidArgumentException;

final class GroupSheetUnsupportedSizeException extends InvalidArgumentException
{
    public static function forSize(int $size): self
    {
        return new self(sprintf(
            'No hay patrón operativo de planilla G3/G4/G5 para grupos de %d participantes.',
            $size,
        ));
    }
}
