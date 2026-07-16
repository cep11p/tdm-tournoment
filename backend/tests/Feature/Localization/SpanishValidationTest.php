<?php

namespace Tests\Feature\Localization;

use Tests\TestCase;

class SpanishValidationTest extends TestCase
{
    public function test_standard_validation_messages_are_returned_in_spanish(): void
    {
        $response = $this->postJson('/api/v1/tournaments', []);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'El campo nombre es obligatorio.')
            ->assertJsonPath('errors.location.0', 'El campo ubicación es obligatorio.')
            ->assertJsonPath('errors.start_date.0', 'El campo fecha de inicio es obligatorio.');
    }
}
