<?php

namespace App\Actions\Group;

use App\Models\Group;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

final class CreateGroupAction
{
    public function __invoke(array $payload): Group
    {
        try {
            return Group::query()->create($payload);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw ValidationException::withMessages([
                    'name' => ['Ya existe un grupo con ese nombre en esta competencia.'],
                ]);
            }

            throw $exception;
        }
    }
}
