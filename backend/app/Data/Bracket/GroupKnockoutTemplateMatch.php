<?php

namespace App\Data\Bracket;

use InvalidArgumentException;

final class GroupKnockoutTemplateMatch
{
    public function __construct(
        public int $bracketMatch,
        public GroupKnockoutTemplateSlot $side1,
        public ?GroupKnockoutTemplateSlot $side2,
    ) {
        if ($this->bracketMatch < 1) {
            throw new InvalidArgumentException('El número de partido de una plantilla debe ser al menos 1.');
        }
    }

    public function isBye(): bool
    {
        return $this->side2 === null;
    }
}
