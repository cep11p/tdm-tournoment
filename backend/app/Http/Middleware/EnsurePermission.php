<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use App\Support\Auth\AuthenticatedContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePermission
{
    /**
     * @param  string  ...$permissions  Permission enum values (e.g. tournaments.manage)
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $context = $request->attributes->get(AuthenticatedContext::ATTRIBUTE);

        if (! $context instanceof AuthenticatedContext) {
            return response()->json([
                'message' => 'No autenticado.',
                'code' => 'unauthenticated',
            ], Response::HTTP_UNAUTHORIZED);
        }

        foreach ($permissions as $permissionValue) {
            $permission = Permission::from($permissionValue);

            if ($context->has($permission)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'No autorizado.',
            'code' => 'forbidden',
        ], Response::HTTP_FORBIDDEN);
    }
}
