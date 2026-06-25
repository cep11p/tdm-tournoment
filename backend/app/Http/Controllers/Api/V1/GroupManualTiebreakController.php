<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Group\ApplyGroupManualTiebreakAction;
use App\Enums\ManualTiebreakReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Group\ApplyGroupManualTiebreakRequest;
use App\Http\Resources\Group\GroupManualTiebreakResource;
use App\Models\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class GroupManualTiebreakController extends Controller
{
    public function store(
        ApplyGroupManualTiebreakRequest $request,
        Group $group,
        ApplyGroupManualTiebreakAction $applyManualTiebreak,
    ): JsonResponse {
        $tiebreak = $applyManualTiebreak($group, [
            'player_ids' => $request->validated('player_ids'),
            'reason' => ManualTiebreakReason::from($request->validated('reason')),
            'notes' => $request->validated('notes'),
        ]);

        return (new GroupManualTiebreakResource($tiebreak))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
