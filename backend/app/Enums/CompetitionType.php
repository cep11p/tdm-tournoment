<?php

namespace App\Enums;

enum CompetitionType: string
{
    case Singles = 'singles';
    case Doubles = 'doubles';
    case Team = 'team';

    public function isSingles(): bool
    {
        return $this === self::Singles;
    }

    public function isDoubles(): bool
    {
        return $this === self::Doubles;
    }

    public function isTeam(): bool
    {
        return $this === self::Team;
    }

    public function isMultiMember(): bool
    {
        return $this === self::Doubles || $this === self::Team;
    }
}
