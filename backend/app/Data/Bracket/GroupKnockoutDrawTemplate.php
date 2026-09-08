<?php

namespace App\Data\Bracket;

final class GroupKnockoutDrawTemplate
{
    /**
     * @param  list<GroupKnockoutTemplateMatch>  $matches
     */
    public function __construct(
        public int $groupCount,
        public int $qualifiedPerGroup,
        public int $bracketSize,
        public array $matches,
    ) {}

    public function qualifierCount(): int
    {
        return $this->groupCount * $this->qualifiedPerGroup;
    }

    public function byesCount(): int
    {
        return $this->bracketSize - $this->qualifierCount();
    }
}
