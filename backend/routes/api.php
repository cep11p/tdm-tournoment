<?php

use App\Http\Controllers\Api\V1\TournamentController;
use Illuminate\Support\Facades\Route;

Route::prefix(config('api.version_prefix', 'v1'))
    ->group(function (): void {
        Route::apiResource('tournaments', TournamentController::class)
            ->only(['store', 'index', 'show', 'update']);
    });
