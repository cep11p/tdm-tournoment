<?php

namespace App\Enums;

enum CompetitionFormat: string
{
    /** @deprecated Use GroupsKnockout instead. Kept for backward compatibility. */
    case Manual = 'manual';

    case GroupsKnockout = 'groups_knockout';

    case KnockoutDirect = 'knockout_direct';

    public function normalized(): self
    {
        return $this === self::Manual ? self::GroupsKnockout : $this;
    }

    public function hasGroupStage(): bool
    {
        return $this->normalized() === self::GroupsKnockout;
    }

    public function isKnockoutDirect(): bool
    {
        return $this->normalized() === self::KnockoutDirect;
    }

    public function label(): string
    {
        return match ($this->normalized()) {
            self::GroupsKnockout => 'Fase de grupos + eliminatoria',
            self::KnockoutDirect => 'Eliminación directa',
        };
    }
}
