<?php

use Illuminate\Support\Facades\Route;

Route::prefix(config('api.version_prefix', 'v1'))
    ->group(function (): void {
        // Endpoints del MVP se agregan en la siguiente etapa.
    });
