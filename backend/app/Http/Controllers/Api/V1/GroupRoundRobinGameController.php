<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Group\GenerateGroupRoundRobinGamesAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Game\GameResource;
use App\Models\Game;
use App\Models\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class GroupRoundRobinGameController extends Controller
{
    private const GAME_RELATIONS = [
        'player1:id,first_name,last_name,nickname',
        'player2:id,first_name,last_name,nickname',
        'winner:id,first_name,last_name,nickname',
        'sets',
    ];

    public function store(
        Group $group,
        GenerateGroupRoundRobinGamesAction $generateRoundRobin
    ): JsonResponse {
        $createdGameIds = $generateRoundRobin($group)->pluck('id');

        $games = Game::query()
            ->whereIn('id', $createdGameIds)
            ->with(self::GAME_RELATIONS)
            ->orderBy('id')
            ->get();

        return GameResource::collection($games)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
