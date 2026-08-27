<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Registration\BulkRegisterPlayersToCompetitionAction;
use App\Actions\Registration\RegisterPlayerToCompetitionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Registration\BulkStoreRegistrationRequest;
use App\Http\Requests\Registration\StoreRegistrationRequest;
use App\Http\Resources\Registration\RegistrationResource;
use App\Models\Competition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class RegistrationController extends Controller
{
    public function index(Competition $competition): AnonymousResourceCollection
    {
        $entries = $competition->entries()
            ->with(['members.player:id,first_name,last_name,nickname'])
            ->latest('id')
            ->get();

        return RegistrationResource::collection($entries);
    }

    public function store(
        StoreRegistrationRequest $request,
        Competition $competition,
        RegisterPlayerToCompetitionAction $registerPlayer
    ): JsonResponse {
        $entry = $registerPlayer([
            ...$request->validated(),
            'competition_id' => $competition->id,
        ])->load(['members.player:id,first_name,last_name,nickname']);

        return (new RegistrationResource($entry))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function bulkStore(
        BulkStoreRegistrationRequest $request,
        Competition $competition,
        BulkRegisterPlayersToCompetitionAction $bulkRegister,
    ): JsonResponse {
        $result = $bulkRegister($competition->id, $request->validated('player_ids'));

        return response()->json([
            'message' => 'Inscripción masiva procesada.',
            ...$result,
        ]);
    }
}
