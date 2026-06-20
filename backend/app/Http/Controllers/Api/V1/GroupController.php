<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Group\CreateGroupAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Group\StoreGroupRequest;
use App\Http\Resources\Group\GroupResource;
use App\Models\Competition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class GroupController extends Controller
{
    public function index(Competition $competition): AnonymousResourceCollection
    {
        $groups = $competition->groups()
            ->latest('id')
            ->get();

        return GroupResource::collection($groups);
    }

    public function store(
        StoreGroupRequest $request,
        Competition $competition,
        CreateGroupAction $createGroup
    ): JsonResponse {
        $group = $createGroup([
            ...$request->validated(),
            'competition_id' => $competition->id,
        ]);

        return (new GroupResource($group))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
