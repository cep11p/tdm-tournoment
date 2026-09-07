<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CheckIn\BulkUpdateCompetitionCheckInAction;
use App\Actions\CheckIn\CheckInCompetitionEntryMemberAction;
use App\Actions\CheckIn\UndoCheckInCompetitionEntryMemberAction;
use App\Enums\CompetitionCheckInBulkAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\CheckIn\BulkUpdateCompetitionCheckInRequest;
use App\Models\Competition;
use App\Models\CompetitionEntryMember;
use App\Support\Competition\BuildCompetitionCheckInPayload;
use Illuminate\Http\JsonResponse;

class CompetitionCheckInController extends Controller
{
    public function show(Competition $competition): JsonResponse
    {
        return response()->json([
            'data' => BuildCompetitionCheckInPayload::for($competition),
        ]);
    }

    public function store(
        Competition $competition,
        CompetitionEntryMember $member,
        CheckInCompetitionEntryMemberAction $checkIn,
    ): JsonResponse {
        return response()->json([
            'data' => $checkIn($competition, $member),
        ]);
    }

    public function destroy(
        Competition $competition,
        CompetitionEntryMember $member,
        UndoCheckInCompetitionEntryMemberAction $undoCheckIn,
    ): JsonResponse {
        return response()->json([
            'data' => $undoCheckIn($competition, $member),
        ]);
    }

    public function bulk(
        BulkUpdateCompetitionCheckInRequest $request,
        Competition $competition,
        BulkUpdateCompetitionCheckInAction $bulkUpdate,
    ): JsonResponse {
        $action = CompetitionCheckInBulkAction::from($request->validated('action'));

        return response()->json([
            'data' => $bulkUpdate($competition, $action),
        ]);
    }
}
