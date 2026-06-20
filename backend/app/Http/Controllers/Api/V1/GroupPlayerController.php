<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\GroupPlayer\AssignPlayerToGroupAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\GroupPlayer\StoreGroupPlayerRequest;
use App\Http\Resources\GroupPlayer\GroupPlayerResource;
use App\Models\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class GroupPlayerController extends Controller
{
    public function index(Group $group): AnonymousResourceCollection
    {
        $groupPlayers = $group->groupPlayers()
            ->with('player:id,first_name,last_name,nickname')
            ->latest('id')
            ->get();

        return GroupPlayerResource::collection($groupPlayers);
    }

    public function store(
        StoreGroupPlayerRequest $request,
        Group $group,
        AssignPlayerToGroupAction $assignPlayer
    ): JsonResponse {
        $groupPlayer = $assignPlayer([
            'group_id' => $group->id,
            'player_id' => $request->validated('player_id'),
        ])->load('player:id,first_name,last_name,nickname');

        return (new GroupPlayerResource($groupPlayer))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
