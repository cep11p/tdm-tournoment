<?php

namespace Database\Seeders\Support;

use App\Enums\CompetitionFormat;
use App\Enums\CompetitionType;
use App\Enums\ThirdPlaceMode;

final readonly class DemoCompetitionConfig
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    public function __construct(
        public string $name,
        public CompetitionType $type = CompetitionType::Singles,
        public CompetitionFormat $format = CompetitionFormat::GroupsKnockout,
        public string $categorySlug = 'primera',
        public int $qualifiedPerGroup = 2,
        public int $groupStageBestOf = 3,
        public int $knockoutStageBestOf = 3,
        public int $semifinalBestOf = 3,
        public int $finalBestOf = 5,
        public ThirdPlaceMode $thirdPlaceMode = ThirdPlaceMode::Playoff,
        public int $pointsPerSet = 11,
        public ?int $teamSize = null,
        public ?int $teamTieFormatId = null,
        public array $overrides = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toPayload(int $tournamentId, int $categoryId): array
    {
        $payload = [
            'tournament_id' => $tournamentId,
            'name' => $this->name,
            'type' => $this->type,
            'category_id' => $categoryId,
            'format' => $this->format,
            'qualified_per_group' => $this->qualifiedPerGroup,
            'points_per_set' => $this->pointsPerSet,
            'group_stage_best_of' => $this->groupStageBestOf,
            'knockout_stage_best_of' => $this->knockoutStageBestOf,
            'semifinal_best_of' => $this->semifinalBestOf,
            'final_best_of' => $this->finalBestOf,
            'third_place_mode' => $this->thirdPlaceMode,
        ];

        if ($this->type === CompetitionType::Team) {
            $payload['team_size'] = $this->teamSize;
            $payload['team_tie_format_id'] = $this->teamTieFormatId;
        }

        return array_merge($payload, $this->overrides);
    }
}
