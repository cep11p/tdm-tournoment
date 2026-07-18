<?php

namespace App\Http\Resources\Competition;

use App\Enums\CompetitionFormat;
use App\Enums\CompetitionType;
use App\Support\Competition\CompetitionResultResolver;
use App\Support\Competition\CompetitionStatusResolver;
use App\Support\Competition\CompetitionStructureGuard;
use App\Support\Competition\RegistrationGuard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompetitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $type = $this->type instanceof CompetitionType
            ? $this->type->value
            : (string) $this->type;

        $format = $this->format instanceof CompetitionFormat
            ? $this->format->value
            : (string) $this->format;

        $normalizedFormat = $this->format instanceof CompetitionFormat
            ? $this->format->normalized()
            : CompetitionFormat::from((string) $this->format)->normalized();

        return [
            'id' => $this->id,
            'tournament_id' => $this->tournament_id,
            'name' => $this->name,
            'category' => $this->category,
            'category_id' => $this->category_id,
            'category_ref' => $this->whenLoaded('categoryModel', function () {
                if ($this->categoryModel === null) {
                    return null;
                }

                return [
                    'id' => $this->categoryModel->id,
                    'name' => $this->categoryModel->name,
                    'slug' => $this->categoryModel->slug,
                ];
            }),
            'type' => $type,
            'format' => $format,
            'format_label' => $normalizedFormat->label(),
            'has_group_stage' => $normalizedFormat->hasGroupStage(),
            'points_per_set' => $this->points_per_set,
            'group_stage_best_of' => $this->group_stage_best_of,
            'knockout_stage_best_of' => $this->knockout_stage_best_of,
            'semifinal_best_of' => $this->semifinal_best_of,
            'final_best_of' => $this->final_best_of,
            'qualified_per_group' => $this->qualified_per_group,
            'is_structure_editable' => CompetitionStructureGuard::isStructureEditable($this->resource),
            'structure_lock_reason' => CompetitionStructureGuard::structureLockReason($this->resource),
            'is_registrations_editable' => RegistrationGuard::isEditable($this->resource),
            'registrations_lock_reason' => RegistrationGuard::lockReason($this->resource),
            'status_summary' => CompetitionStatusResolver::resolve($this->resource),
            'result_summary' => CompetitionResultResolver::resolve($this->resource),
            'registrations_count' => (int) ($this->registrations_count ?? 0),
            'games_count' => (int) ($this->games_count ?? 0),
            'created_at' => optional($this->created_at)->toISOString(),
            'updated_at' => optional($this->updated_at)->toISOString(),
        ];
    }
}
