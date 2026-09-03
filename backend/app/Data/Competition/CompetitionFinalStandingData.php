<?php

namespace App\Data\Competition;

use App\Enums\CompetitionFinalStandingSource;

final class CompetitionFinalStandingData
{
    public function __construct(
        public int $competitionEntryId,
        public int $position,
        public int $positionRangeEnd,
        public CompetitionFinalStandingSource $source,
        public string $displayNameSnapshot,
    ) {}
}
