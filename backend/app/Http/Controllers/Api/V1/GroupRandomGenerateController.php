<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Group\GenerateRandomGroupsForCompetitionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Group\GenerateRandomGroupsRequest;
use App\Http\Resources\Group\GroupResource;
use App\Models\Competition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class GroupRandomGenerateController extends Controller
{
    public function __invoke(
        GenerateRandomGroupsRequest $request,
        Competition $competition,
        GenerateRandomGroupsForCompetitionAction $generateRandomGroups,
    ): JsonResponse {
        $result = $generateRandomGroups(
            $competition,
            (int) $request->validated('groups_count'),
        );

        return response()->json([
            'message' => 'Grupos generados correctamente.',
            'groups_created' => $result['groups_created'],
            'players_assigned' => $result['players_assigned'],
            'games_created' => $result['games_created'],
            'groups' => GroupResource::collection($result['groups'])->resolve(),
        ], Response::HTTP_CREATED);
    }
}
