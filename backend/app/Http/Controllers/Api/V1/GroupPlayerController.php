<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\GroupPlayer\AssignPlayerToGroupAction;
use App\Actions\GroupPlayer\MoveCompetitionEntryBetweenGroupsAction;
use App\Actions\GroupPlayer\RemoveEntryFromGroupAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\GroupPlayer\MoveGroupPlayerRequest;
use App\Http\Requests\GroupPlayer\StoreGroupPlayerRequest;
use App\Http\Resources\GroupPlayer\GroupPlayerResource;
use App\Models\CompetitionEntry;
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

    public function destroy(
        Group $group,
        CompetitionEntry $competitionEntry,
        RemoveEntryFromGroupAction $removeEntry,
    ): Response {
        $removeEntry($group, (int) $competitionEntry->id);

        return response()->noContent();
    }

    public function move(
        MoveGroupPlayerRequest $request,
        Group $group,
        MoveCompetitionEntryBetweenGroupsAction $moveEntry,
    ): JsonResponse {
        $groupEntry = $moveEntry(
            $group,
            (int) $request->validated('competition_entry_id'),
            (int) $request->validated('target_group_id'),
        );

        return (new GroupPlayerResource($groupEntry))->response();
    }
}
