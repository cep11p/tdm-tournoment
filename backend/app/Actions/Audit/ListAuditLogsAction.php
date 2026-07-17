<?php

namespace App\Actions\Audit;

use App\Enums\AuditSubjectType;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

final class ListAuditLogsAction
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function __invoke(array $filters): LengthAwarePaginator
    {
        $query = Activity::query()
            ->with(['causer', 'subject'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($action = $filters['action'] ?? null) {
            $query->where('description', $action);
        }

        if ($logName = $filters['log_name'] ?? null) {
            $query->where('log_name', $logName);
        }

        if ($actorId = $filters['actor_id'] ?? null) {
            $query->where('causer_type', User::class)
                ->where('causer_id', $actorId);
        }

        if ($tournamentId = $filters['tournament_id'] ?? null) {
            $query->where('properties->context->tournament_id', $tournamentId);
        }

        if ($competitionId = $filters['competition_id'] ?? null) {
            $query->where('properties->context->competition_id', $competitionId);
        }

        if ($groupId = $filters['group_id'] ?? null) {
            $query->where('properties->context->group_id', $groupId);
        }

        if ($gameId = $filters['game_id'] ?? null) {
            $query->where('properties->context->game_id', $gameId);
        }

        if ($subjectType = $filters['subject_type'] ?? null) {
            $enum = AuditSubjectType::from($subjectType);
            $query->where('subject_type', $enum->modelClass());

            if ($subjectId = $filters['subject_id'] ?? null) {
                $query->where('subject_id', $subjectId);
            }
        } elseif ($subjectId = $filters['subject_id'] ?? null) {
            $query->where('subject_id', $subjectId);
        }

        $timezone = config('app.timezone');

        if ($from = $filters['from'] ?? null) {
            $query->where(
                'created_at',
                '>=',
                Carbon::parse($from, $timezone)->startOfDay(),
            );
        }

        if ($to = $filters['to'] ?? null) {
            $query->where(
                'created_at',
                '<=',
                Carbon::parse($to, $timezone)->endOfDay(),
            );
        }

        if ($search = $filters['search'] ?? null) {
            $term = addcslashes(trim($search), '%_\\');
            $like = '%'.$term.'%';

            $query->where(function ($subQuery) use ($like): void {
                $subQuery->where('description', 'like', $like)
                    ->orWhere('properties->context->tournament_name', 'like', $like)
                    ->orWhere('properties->context->competition_name', 'like', $like)
                    ->orWhere('properties->context->group_name', 'like', $like)
                    ->orWhere('properties->context->player_name', 'like', $like)
                    ->orWhereHas('causer', function ($causerQuery) use ($like): void {
                        $causerQuery->where('name', 'like', $like)
                            ->orWhere('email', 'like', $like);
                    });
            });
        }

        $perPage = min((int) ($filters['per_page'] ?? 25), 100);
        $page = max((int) ($filters['page'] ?? 1), 1);

        return $query->paginate($perPage, ['*'], 'page', $page)->withQueryString();
    }
}
