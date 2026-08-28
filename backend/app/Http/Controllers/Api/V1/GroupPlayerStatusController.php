<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Group\SetGroupEntryStatusAction;
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
        SetGroupEntryStatusAction $setGroupEntryStatus,
    ): JsonResponse {
        $reason = $request->validated('reason');

        $groupEntry = $setGroupEntryStatus($group, [
            'player_id' => $request->input('player_id'),
            'competition_entry_id' => $request->input('competition_entry_id'),
            'status' => GroupPlayerStatus::from($request->validated('status')),
            'reason' => $reason !== null ? GroupPlayerStatusReason::from($reason) : null,
            'notes' => $request->validated('notes'),
        ]);

        return (new GroupPlayerResource($groupEntry))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
