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
        $groupEntries = $group->groupEntries()
            ->with(['competitionEntry.members.player:id,first_name,last_name,nickname'])
            ->latest('id')
            ->get();

        return GroupPlayerResource::collection($groupEntries);
    }

    public function store(
        StoreGroupPlayerRequest $request,
        Group $group,
        AssignPlayerToGroupAction $assignPlayer
    ): JsonResponse {
        $groupEntry = $assignPlayer([
            'group_id' => $group->id,
            'player_id' => $request->input('player_id'),
            'competition_entry_id' => $request->input('competition_entry_id'),
        ])->load([
            'competitionEntry.members.player:id,first_name,last_name,nickname',
        ]);

        return (new GroupPlayerResource($groupEntry))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
