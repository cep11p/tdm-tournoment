<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateKeycloak;
use App\Support\Auth\AuthenticatedIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthenticatedUserController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var AuthenticatedIdentity $identity */
        $identity = $request->attributes->get(AuthenticateKeycloak::IDENTITY_ATTRIBUTE);

        $user = $request->user();

        return response()->json([
            'data' => [
                'id' => $user?->id,
                'keycloak_id' => $user?->keycloak_id,
                'name' => $user?->name,
                'email' => $user?->email,
                'roles' => $identity->roles,
            ],
        ]);
    }
}
