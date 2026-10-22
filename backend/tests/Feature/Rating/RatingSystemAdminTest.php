<?php

namespace Tests\Feature\Rating;

use App\Enums\AuditAction;
use App\Enums\RatingMethod;
use App\Models\RatingSystem;
use Illuminate\Database\QueryException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class RatingSystemAdminTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Rating del club',
            'description' => 'Sistema inicial',
            'initial_rating' => 1500,
            'method' => RatingMethod::DifferenceTable->value,
        ], $overrides);
    }

    /**
     * @return array<string, string>
     */
    private function adminHeaders(): array
    {
        return $this->keycloakAuthHeaders(['admin']);
    }

    public function test_reads_require_ratings_manage(): void
    {
        $this->getJson('/api/v1/rating-systems')
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'No autenticado.',
                'code' => 'unauthenticated',
            ]);

        $this->getJson('/api/v1/rating-systems', $this->keycloakAuthHeaders(['organizer']))
            ->assertForbidden()
            ->assertJson([
                'message' => 'No autorizado.',
                'code' => 'forbidden',
            ]);

        $created = $this->postJson('/api/v1/rating-systems', $this->validPayload([
            'name' => 'Zulu',
        ]), $this->adminHeaders())
            ->assertCreated()
            ->json('data');

        $this->getJson("/api/v1/rating-systems/{$created['id']}")
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'No autenticado.',
                'code' => 'unauthenticated',
            ]);

        $this->getJson("/api/v1/rating-systems/{$created['id']}", $this->keycloakAuthHeaders(['player']))
            ->assertForbidden();

        $this->getJson("/api/v1/rating-systems/{$created['id']}", $this->keycloakAuthHeaders(['organizer']))
            ->assertForbidden();

        $this->postJson('/api/v1/rating-systems', $this->validPayload([
            'name' => 'Alfa',
        ]), $this->adminHeaders())->assertCreated();

        $names = collect($this->getJson('/api/v1/rating-systems', $this->adminHeaders())->assertOk()->json('data'))
            ->pluck('name')
            ->all();

        $this->assertSame(['Alfa', 'Zulu'], $names);

        $this->getJson("/api/v1/rating-systems/{$created['id']}", $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('data.name', 'Zulu')
            ->assertJsonPath('data.method', 'difference_table');
    }

    public function test_admin_can_create_a_valid_rating_system(): void
    {
        $data = $this->postJson(
            '/api/v1/rating-systems',
            $this->validPayload(['name' => '  Rating del club  ']),
            $this->adminHeaders(),
        )
            ->assertCreated()
            ->json('data');

        $this->assertSame('Rating del club', $data['name']);
        $this->assertSame('Sistema inicial', $data['description']);
        $this->assertSame(1500, $data['initial_rating']);
        $this->assertSame('difference_table', $data['method']);
        $this->assertTrue($data['active']);

        $this->assertDatabaseHas('rating_systems', [
            'id' => $data['id'],
            'name' => 'Rating del club',
            'initial_rating' => 1500,
            'method' => 'difference_table',
            'active' => true,
        ]);
    }

    public function test_create_defaults_active_to_true_and_description_to_null(): void
    {
        $payload = $this->validPayload();
        unset($payload['active'], $payload['description']);

        $this->postJson('/api/v1/rating-systems', $payload, $this->adminHeaders())
            ->assertCreated()
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.description', null);
    }

    public function test_required_fields_are_rejected(): void
    {
        $this->postJson('/api/v1/rating-systems', [], $this->adminHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'initial_rating', 'method']);

        $this->postJson(
            '/api/v1/rating-systems',
            $this->validPayload(['name' => '   ']),
            $this->adminHeaders(),
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_invalid_initial_rating_is_rejected(): void
    {
        foreach ([-1, 10.5, 'abc', 4294967296] as $initialRating) {
            $this->postJson(
                '/api/v1/rating-systems',
                $this->validPayload([
                    'name' => 'Rating '.$initialRating,
                    'initial_rating' => $initialRating,
                ]),
                $this->adminHeaders(),
            )
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['initial_rating']);
        }

        $this->postJson(
            '/api/v1/rating-systems',
            $this->validPayload(['name' => 'Rating cero', 'initial_rating' => 0]),
            $this->adminHeaders(),
        )
            ->assertCreated()
            ->assertJsonPath('data.initial_rating', 0);
    }

    public function test_unknown_method_is_rejected(): void
    {
        $this->postJson(
            '/api/v1/rating-systems',
            $this->validPayload(['method' => 'elo']),
            $this->adminHeaders(),
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['method']);

        $this->assertDatabaseCount('rating_systems', 0);
    }

    public function test_name_must_be_unique(): void
    {
        $this->postJson('/api/v1/rating-systems', $this->validPayload(), $this->adminHeaders())
            ->assertCreated();

        $this->postJson(
            '/api/v1/rating-systems',
            $this->validPayload(['description' => 'Otro']),
            $this->adminHeaders(),
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name'])
            ->assertJsonPath('errors.name.0', 'Ya existe un sistema de rating con ese nombre.');

        $this->postJson(
            '/api/v1/rating-systems',
            $this->validPayload(['name' => 'Rating copa']),
            $this->adminHeaders(),
        )->assertCreated();

        $original = RatingSystem::query()->where('name', 'Rating del club')->firstOrFail();

        $this->patchJson(
            "/api/v1/rating-systems/{$original->id}",
            ['name' => 'Rating del club'],
            $this->adminHeaders(),
        )->assertOk();

        $this->patchJson(
            "/api/v1/rating-systems/{$original->id}",
            ['name' => 'Rating copa'],
            $this->adminHeaders(),
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        try {
            RatingSystem::query()->create([
                'name' => 'Rating del club',
                'initial_rating' => 1000,
                'method' => RatingMethod::DifferenceTable->value,
                'active' => true,
            ]);
            $this->fail('Se insertó un nombre duplicado.');
        } catch (QueryException) {
            $this->assertSame(2, RatingSystem::query()->count());
        }
    }

    public function test_admin_can_update_a_rating_system(): void
    {
        $created = $this->postJson('/api/v1/rating-systems', $this->validPayload(), $this->adminHeaders())
            ->assertCreated()
            ->json('data');

        $data = $this->patchJson(
            "/api/v1/rating-systems/{$created['id']}",
            [
                'name' => '  Rating corregido  ',
                'description' => '   ',
                'initial_rating' => 1600,
                'method' => RatingMethod::DifferenceTable->value,
            ],
            $this->adminHeaders(),
        )
            ->assertOk()
            ->json('data');

        $this->assertSame('Rating corregido', $data['name']);
        $this->assertNull($data['description']);
        $this->assertSame(1600, $data['initial_rating']);
        $this->assertSame('difference_table', $data['method']);
        $this->assertTrue($data['active']);
    }

    public function test_admin_can_activate_and_deactivate_a_rating_system(): void
    {
        $created = $this->postJson(
            '/api/v1/rating-systems',
            $this->validPayload(['active' => false]),
            $this->adminHeaders(),
        )
            ->assertCreated()
            ->assertJsonPath('data.active', false)
            ->json('data');

        $this->patchJson(
            "/api/v1/rating-systems/{$created['id']}",
            ['active' => true],
            $this->adminHeaders(),
        )
            ->assertOk()
            ->assertJsonPath('data.active', true);

        $this->patchJson(
            "/api/v1/rating-systems/{$created['id']}",
            ['active' => false],
            $this->adminHeaders(),
        )
            ->assertOk()
            ->assertJsonPath('data.active', false);

        $this->assertDatabaseHas('rating_systems', [
            'id' => $created['id'],
            'active' => false,
        ]);
    }

    public function test_unauthorized_users_cannot_create_or_update(): void
    {
        $created = $this->postJson('/api/v1/rating-systems', $this->validPayload(), $this->adminHeaders())
            ->assertCreated()
            ->json('data');

        $this->postJson('/api/v1/rating-systems', $this->validPayload(['name' => 'Otro']))
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'No autenticado.',
                'code' => 'unauthenticated',
            ]);

        $this->patchJson("/api/v1/rating-systems/{$created['id']}", ['name' => 'Otro'])
            ->assertUnauthorized();

        foreach (['organizer', 'scorekeeper', 'player'] as $role) {
            $this->postJson(
                '/api/v1/rating-systems',
                $this->validPayload(['name' => 'Otro '.$role]),
                $this->keycloakAuthHeaders([$role]),
            )
                ->assertForbidden()
                ->assertJson([
                    'message' => 'No autorizado.',
                    'code' => 'forbidden',
                ]);

            $this->patchJson(
                "/api/v1/rating-systems/{$created['id']}",
                ['active' => false],
                $this->keycloakAuthHeaders([$role]),
            )->assertForbidden();
        }

        $this->assertDatabaseHas('rating_systems', [
            'id' => $created['id'],
            'name' => 'Rating del club',
            'active' => true,
        ]);
    }

    public function test_administrative_operations_are_audited(): void
    {
        Activity::query()->delete();

        $this->postJson(
            '/api/v1/rating-systems',
            $this->validPayload(['method' => 'elo']),
            $this->adminHeaders(),
        )->assertUnprocessable();

        $this->assertSame(0, Activity::query()->count());

        $created = $this->postJson(
            '/api/v1/rating-systems',
            $this->validPayload(['active' => false]),
            $this->adminHeaders(),
        )
            ->assertCreated()
            ->json('data');

        $createdActivity = Activity::query()
            ->where('description', AuditAction::RATING_SYSTEM_CREATED->value)
            ->sole();

        $this->assertSame('rating_systems', $createdActivity->log_name);
        $this->assertSame(RatingSystem::class, $createdActivity->subject_type);
        $this->assertSame($created['id'], data_get($createdActivity->properties, 'summary.rating_system_id'));
        $this->assertSame('Rating del club', data_get($createdActivity->properties, 'summary.rating_system_name'));
        $this->assertSame(1500, data_get($createdActivity->properties, 'new.initial_rating'));
        $this->assertSame('difference_table', data_get($createdActivity->properties, 'new.method'));
        $this->assertFalse(data_get($createdActivity->properties, 'new.active'));
        $this->assertSame(
            0,
            Activity::query()->where('description', AuditAction::RATING_SYSTEM_ACTIVATED->value)->count(),
        );

        $this->getJson(
            '/api/v1/audit-logs?subject_type=rating_system&subject_id='.$created['id'],
            $this->adminHeaders(),
        )
            ->assertOk()
            ->assertJsonPath('data.0.action', 'rating_system.created')
            ->assertJsonPath('data.0.action_label', 'Creación de sistema de rating')
            ->assertJsonPath('data.0.category_label', 'Sistemas de rating')
            ->assertJsonPath('data.0.subject.type', 'rating_system')
            ->assertJsonPath('data.0.subject.label', 'Rating del club');

        Activity::query()->delete();

        $this->patchJson(
            "/api/v1/rating-systems/{$created['id']}",
            ['name' => 'Rating del club'],
            $this->adminHeaders(),
        )->assertOk();

        $this->assertSame(0, Activity::query()->count());

        $this->patchJson(
            "/api/v1/rating-systems/{$created['id']}",
            ['description' => 'Ajuste de texto'],
            $this->adminHeaders(),
        )->assertOk();

        $this->assertSame(
            1,
            Activity::query()->where('description', AuditAction::RATING_SYSTEM_UPDATED->value)->count(),
        );
        $this->assertSame(
            ['description'],
            data_get(
                Activity::query()->where('description', AuditAction::RATING_SYSTEM_UPDATED->value)->sole()->properties,
                'summary.changed_fields',
            ),
        );

        Activity::query()->delete();

        $this->patchJson(
            "/api/v1/rating-systems/{$created['id']}",
            ['active' => true],
            $this->adminHeaders(),
        )->assertOk();

        $this->assertSame(
            1,
            Activity::query()->where('description', AuditAction::RATING_SYSTEM_ACTIVATED->value)->count(),
        );
        $this->assertSame(
            0,
            Activity::query()->where('description', AuditAction::RATING_SYSTEM_UPDATED->value)->count(),
        );

        Activity::query()->delete();

        $this->patchJson(
            "/api/v1/rating-systems/{$created['id']}",
            ['name' => 'Rating cerrado', 'active' => false],
            $this->adminHeaders(),
        )->assertOk();

        $deactivated = Activity::query()
            ->where('description', AuditAction::RATING_SYSTEM_DEACTIVATED->value)
            ->sole();

        $this->assertEqualsCanonicalizing(
            ['name', 'active'],
            data_get($deactivated->properties, 'summary.changed_fields'),
        );
        $this->assertSame(
            0,
            Activity::query()->where('description', AuditAction::RATING_SYSTEM_UPDATED->value)->count(),
        );
    }
}
