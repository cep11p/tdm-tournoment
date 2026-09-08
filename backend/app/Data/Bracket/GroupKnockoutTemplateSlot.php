<?php

namespace App\Data\Bracket;

use InvalidArgumentException;

final class GroupKnockoutTemplateSlot
{
    public function __construct(
        public int $groupIndex,
        public int $groupPosition,
    ) {
        if ($this->groupIndex < 0) {
            throw new InvalidArgumentException('El índice de grupo de una plantilla no puede ser negativo.');
        }

        if ($this->groupPosition < 1) {
            throw new InvalidArgumentException('La posición de grupo de una plantilla debe ser al menos 1.');
        }
    }
}
