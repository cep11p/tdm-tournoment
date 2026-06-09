<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Registration\RegisterPlayerToCompetitionAction;
use App\Http\Controllers\Controller;
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
        $registrations = $competition->registrations()
            ->with('player:id,first_name,last_name,nickname')
            ->latest('id')
            ->get();

        return RegistrationResource::collection($registrations);
    }

    public function store(
        StoreRegistrationRequest $request,
        Competition $competition,
        RegisterPlayerToCompetitionAction $registerPlayer
    ): JsonResponse {
        $registration = $registerPlayer([
            ...$request->validated(),
            'competition_id' => $competition->id,
        ])->load('player:id,first_name,last_name,nickname');

        return (new RegistrationResource($registration))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
