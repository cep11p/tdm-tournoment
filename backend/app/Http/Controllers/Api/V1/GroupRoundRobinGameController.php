<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Group\GenerateGroupRoundRobinScheduleAction;
use App\Enums\CompetitionType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Game\GameResource;
use App\Http\Resources\TeamTie\TeamTieResource;
use App\Models\Game;
use App\Models\Group;
use App\Models\TeamTie;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class GroupRoundRobinGameController extends Controller
{
    private const GAME_RELATIONS = Game::DISPLAY_RELATIONS;

    private const TEAM_TIE_RELATIONS = TeamTie::DISPLAY_RELATIONS;

    public function store(
        Group $group,
        GenerateGroupRoundRobinScheduleAction $generateRoundRobin
    ): JsonResponse {
        $group->loadMissing('competition');

        $type = $group->competition->type instanceof CompetitionType
            ? $group->competition->type
            : CompetitionType::from((string) $group->competition->type);

        $created = $generateRoundRobin($group);

        if ($type === CompetitionType::Team) {
            $teamTieIds = $created->pluck('id');

            $teamTies = TeamTie::query()
                ->whereIn('id', $teamTieIds)
                ->with(self::TEAM_TIE_RELATIONS)
                ->orderByRaw('group_round IS NULL')
                ->orderBy('group_round')
                ->orderBy('group_match')
                ->orderBy('id')
                ->get();

            return TeamTieResource::collection($teamTies)
                ->response()
                ->setStatusCode(Response::HTTP_CREATED);
        }

        $createdGameIds = $created->pluck('id');

        $games = Game::query()
            ->whereIn('id', $createdGameIds)
            ->with(self::GAME_RELATIONS)
            ->orderByRaw('group_round IS NULL')
            ->orderBy('group_round')
            ->orderBy('group_match')
            ->orderBy('id')
            ->get();

        return GameResource::collection($games)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
