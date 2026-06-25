<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Group\SetGroupPlayerStatusAction;
use App\Enums\GroupPlayerStatus;
use App\Enums\GroupPlayerStatusReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Group\SetGroupPlayerStatusRequest;
use App\Http\Resources\GroupPlayer\GroupPlayerResource;
use App\Models\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class GroupPlayerStatusController extends Controller
{
    public function store(
        SetGroupPlayerStatusRequest $request,
        Group $group,
        SetGroupPlayerStatusAction $setGroupPlayerStatus,
    ): JsonResponse {
        $reason = $request->validated('reason');

        $groupPlayer = $setGroupPlayerStatus($group, [
            'player_id' => (int) $request->validated('player_id'),
            'status' => GroupPlayerStatus::from($request->validated('status')),
            'reason' => $reason !== null ? GroupPlayerStatusReason::from($reason) : null,
            'notes' => $request->validated('notes'),
        ]);

        return (new GroupPlayerResource($groupPlayer))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
