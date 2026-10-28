<?php

namespace Tests\Feature\Rating;

use App\Actions\Rating\PublishRatingRuleVersionAction;
use App\Enums\AuditAction;
use App\Enums\RatingMethod;
use App\Enums\RatingRuleVersionStatus;
use App\Models\RatingRuleVersion;
use App\Models\RatingSystem;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class RatingRuleVersionAdminTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function validTable(): array
    {
        return [
            ['min' => 0, 'max' => 24, 'favorite_win' => 12, 'underdog_win' => 12],
            ['min' => 25, 'max' => 49, 'favorite_win' => 11, 'underdog_win' => 14],
            ['min' => 50, 'max' => null, 'favorite_win' => 10, 'underdog_win' => 16],
        ];
    }

    private function system(string $name = 'Rating del club'): RatingSystem
    {
        return RatingSystem::query()->create([
            'name' => $name,
            'description' => null,
            'initial_rating' => 1500,
            'method' => RatingMethod::DifferenceTable,
            'active' => true,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     * @return array<string, mixed>
     */
    private function createDraft(RatingSystem $system, array $rules = []): array
    {
        return $this->postJson(
            "/api/v1/rating-systems/{$system->id}/versions",
            ['rules' => $rules],
            $this->authHeaders(['admin']),
        )->assertCreated()->json('data');
    }

    public function test_versions_are_numbered_per_system_and_start_as_drafts(): void
    {
        $club = $this->system();
        $copa = $this->system('Rating copa');

        $first = $this->createDraft($club);
        $second = $this->createDraft($club, $this->validTable());
        $other = $this->createDraft($copa);

        $this->assertSame(1, $first['version']);
        $this->assertSame(2, $second['version']);
        $this->assertSame(1, $other['version']);
        $this->assertSame('draft', $first['status']);
        $this->assertNull($first['published_at']);
        $this->assertSame([], $first['rules']);

        $versions = $this->getJson(
            "/api/v1/rating-systems/{$club->id}/versions",
            $this->authHeaders(['admin']),
        )->assertOk()->json('data');

        $this->assertSame([2, 1], array_column($versions, 'version'));

        $this->getJson(
            "/api/v1/rating-systems/{$club->id}/versions/{$second['id']}",
            $this->authHeaders(['admin']),
        )
            ->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.rules.2.max', null);

        $this->getJson(
            "/api/v1/rating-systems/{$copa->id}/versions/{$second['id']}",
            $this->authHeaders(['admin']),
        )->assertNotFound();
    }

    public function test_drafts_can_be_edited_until_they_are_published(): void
    {
        $system = $this->system();
        $draft = $this->createDraft($system);

        $this->patchJson(
            "/api/v1/rating-systems/{$system->id}/versions/{$draft['id']}",
            [
                'version' => 9,
                'status' => 'published',
                'rules' => $this->validTable(),
            ],
            $this->authHeaders(['admin']),
        )
            ->assertOk()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.rules.0.favorite_win', 12);

        $this->patchJson(
            "/api/v1/rating-systems/{$system->id}/versions/{$draft['id']}",
            ['rules' => $this->validTable()],
            $this->authHeaders(['admin']),
        )->assertOk();

        $this->assertSame(
            1,
            Activity::query()->where('description', AuditAction::RATING_VERSION_UPDATED->value)->count(),
        );
    }

    public function test_incomplete_draft_can_be_saved_but_not_published(): void
    {
        $system = $this->system();
        $draft = $this->createDraft($system);
        $gap = [
            ['min' => 0, 'max' => 10, 'favorite_win' => 1, 'underdog_win' => 1],
            ['min' => 20, 'max' => null, 'favorite_win' => 1, 'underdog_win' => 1],
        ];

        $this->patchJson(
            "/api/v1/rating-systems/{$system->id}/versions/{$draft['id']}",
            ['rules' => $gap],
            $this->authHeaders(['admin']),
        )
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');

        Activity::query()->delete();

        $response = $this->postJson(
            "/api/v1/rating-systems/{$system->id}/versions/{$draft['id']}/publish",
            [],
            $this->authHeaders(['admin']),
        )->assertUnprocessable();

        $this->assertSame(
            'Los rangos deben ser contiguos, sin huecos.',
            $response->json('errors')['rules.1.min'][0],
        );

        $this->assertDatabaseHas('rating_rule_versions', [
            'id' => $draft['id'],
            'status' => 'draft',
            'published_at' => null,
        ]);
        $this->assertSame(0, Activity::query()->count());
    }

    public function test_valid_table_can_be_published_once(): void
    {
        $system = $this->system();
        $draft = $this->createDraft($system, [
            ['min' => 0, 'max' => 0, 'favorite_win' => 0, 'underdog_win' => 1],
            ['min' => 1, 'max' => null, 'favorite_win' => 2, 'underdog_win' => 3],
        ]);

        $published = $this->postJson(
            "/api/v1/rating-systems/{$system->id}/versions/{$draft['id']}/publish",
            [],
            $this->authHeaders(['admin']),
        )
            ->assertOk()
            ->json('data');

        $this->assertSame('published', $published['status']);
        $this->assertNotNull($published['published_at']);

        $stored = RatingRuleVersion::query()->findOrFail($draft['id']);
        $publishedAt = $stored->published_at?->toIso8601String();

        $this->postJson(
            "/api/v1/rating-systems/{$system->id}/versions/{$draft['id']}/publish",
            [],
            $this->authHeaders(['admin']),
        )
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'Esta versión ya está publicada.');

        $stored->refresh();
        $this->assertSame($publishedAt, $stored->published_at?->toIso8601String());
        $this->assertSame(
            1,
            Activity::query()->where('description', AuditAction::RATING_VERSION_PUBLISHED->value)->count(),
        );
    }

    public function test_inactive_system_cannot_publish_a_version(): void
    {
        $system = $this->system();
        $draft = $this->createDraft($system, $this->validTable());

        $this->patchJson(
            "/api/v1/rating-systems/{$system->id}",
            ['active' => false],
            $this->authHeaders(['admin']),
        )->assertOk();

        $this->postJson(
            "/api/v1/rating-systems/{$system->id}/versions/{$draft['id']}/publish",
            [],
            $this->authHeaders(['admin']),
        )
            ->assertUnprocessable()
            ->assertJsonPath('errors.rating_system.0', PublishRatingRuleVersionAction::INACTIVE_SYSTEM_MESSAGE);

        $this->assertDatabaseHas('rating_rule_versions', [
            'id' => $draft['id'],
            'status' => 'draft',
            'published_at' => null,
        ]);
        $this->assertSame(
            0,
            Activity::query()->where('description', AuditAction::RATING_VERSION_PUBLISHED->value)->count(),
        );
    }

    public function test_deactivating_a_system_keeps_published_versions_consultable(): void
    {
        $system = $this->system();
        $draft = $this->createDraft($system, $this->validTable());

        $this->postJson(
            "/api/v1/rating-systems/{$system->id}/versions/{$draft['id']}/publish",
            [],
            $this->authHeaders(['admin']),
        )->assertOk();

        $publishedAt = RatingRuleVersion::query()->findOrFail($draft['id'])->published_at?->toIso8601String();

        $this->patchJson(
            "/api/v1/rating-systems/{$system->id}",
            ['active' => false],
            $this->authHeaders(['admin']),
        )
            ->assertOk()
            ->assertJsonPath('data.active', false);

        $stored = RatingRuleVersion::query()->findOrFail($draft['id']);
        $this->assertSame(RatingRuleVersionStatus::Published, $stored->status);
        $this->assertSame($this->validTable(), $stored->rules);
        $this->assertSame($publishedAt, $stored->published_at?->toIso8601String());

        $this->getJson(
            "/api/v1/rating-systems/{$system->id}/versions/{$draft['id']}",
            $this->authHeaders(['admin']),
        )
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.rules.0.min', 0);
    }

    public function test_publish_rejects_when_the_system_was_deactivated_after_the_version_was_loaded(): void
    {
        $system = $this->system();
        $draft = $this->createDraft($system, $this->validTable());
        $stale = RatingRuleVersion::query()->with('ratingSystem')->findOrFail($draft['id']);
        $this->assertTrue($stale->ratingSystem->active);

        $system->update(['active' => false]);

        try {
            app(PublishRatingRuleVersionAction::class)($stale);
            $this->fail('La publicación debía rechazarse.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                PublishRatingRuleVersionAction::INACTIVE_SYSTEM_MESSAGE,
                $exception->errors()['rating_system'][0],
            );
        }

        $stored = RatingRuleVersion::query()->findOrFail($draft['id']);
        $this->assertSame(RatingRuleVersionStatus::Draft, $stored->status);
        $this->assertNull($stored->published_at);
    }

    public function test_published_versions_cannot_be_changed_or_deleted(): void
    {
        $system = $this->system();
        $draft = $this->createDraft($system, $this->validTable());

        $this->postJson(
            "/api/v1/rating-systems/{$system->id}/versions/{$draft['id']}/publish",
            [],
            $this->authHeaders(['admin']),
        )->assertOk();

        $this->patchJson(
            "/api/v1/rating-systems/{$system->id}/versions/{$draft['id']}",
            ['status' => 'draft', 'rules' => []],
            $this->authHeaders(['admin']),
        )
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'Una versión publicada no se puede modificar.');

        $stored = RatingRuleVersion::query()->findOrFail($draft['id']);
        $this->assertSame($this->validTable(), $stored->rules);
        $this->assertSame(RatingRuleVersionStatus::Published, $stored->status);

        try {
            $stored->rules = [];
            $stored->save();
            $this->fail('Una versión publicada no debería guardarse.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Una versión publicada no se puede modificar.',
                $exception->errors()['status'][0],
            );
        }

        try {
            $stored->refresh()->delete();
            $this->fail('Una versión publicada no debería eliminarse.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'No se puede eliminar una versión publicada.',
                $exception->errors()['status'][0],
            );
        }

        $this->assertDatabaseHas('rating_rule_versions', [
            'id' => $draft['id'],
            'status' => 'published',
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     */
    #[DataProvider('invalidTableProvider')]
    public function test_publish_rejects_invalid_tables(array $rules, string $field, string $message): void
    {
        $system = $this->system('Rating '.$field.$message);
        $draft = $this->createDraft($system, $rules);

        $response = $this->postJson(
            "/api/v1/rating-systems/{$system->id}/versions/{$draft['id']}/publish",
            [],
            $this->authHeaders(['admin']),
        )->assertUnprocessable();

        $this->assertSame($message, $response->json('errors')[$field][0]);

        $this->assertDatabaseHas('rating_rule_versions', [
            'id' => $draft['id'],
            'status' => 'draft',
            'published_at' => null,
        ]);
    }

    /**
     * @return array<string, array{0: list<array<string, mixed>>, 1: string, 2: string}>
     */
    public static function invalidTableProvider(): array
    {
        $points = ['favorite_win' => 1, 'underdog_win' => 1];

        return [
            'vacía' => [[], 'rules', 'La tabla de diferencias no puede estar vacía.'],
            'no comienza en cero' => [
                [array_merge(['min' => 1, 'max' => null], $points)],
                'rules.0.min',
                'El primer rango debe comenzar en 0.',
            ],
            'hueco' => [
                [
                    array_merge(['min' => 0, 'max' => 10], $points),
                    array_merge(['min' => 20, 'max' => null], $points),
                ],
                'rules.1.min',
                'Los rangos deben ser contiguos, sin huecos.',
            ],
            'solapado' => [
                [
                    array_merge(['min' => 0, 'max' => 24], $points),
                    array_merge(['min' => 24, 'max' => null], $points),
                ],
                'rules.1.min',
                'Los rangos no pueden solaparse.',
            ],
            'mínimo negativo' => [
                [array_merge(['min' => -1, 'max' => null], $points)],
                'rules.0.min',
                'El límite inferior debe ser un entero no negativo.',
            ],
            'máximo negativo' => [
                [
                    array_merge(['min' => 0, 'max' => -1], $points),
                    array_merge(['min' => 0, 'max' => null], $points),
                ],
                'rules.0.max',
                'El límite superior debe ser un entero no negativo.',
            ],
            'puntos negativos' => [
                [['min' => 0, 'max' => null, 'favorite_win' => -1, 'underdog_win' => 1]],
                'rules.0.favorite_win',
                'La victoria del favorito debe ser un entero no negativo.',
            ],
            'puntos decimales' => [
                [['min' => 0, 'max' => null, 'favorite_win' => 1.5, 'underdog_win' => 1]],
                'rules.0.favorite_win',
                'La victoria del favorito debe ser un entero no negativo.',
            ],
            'puntos de menor rating inválidos' => [
                [['min' => 0, 'max' => null, 'favorite_win' => 1, 'underdog_win' => '12']],
                'rules.0.underdog_win',
                'La victoria del jugador con menor rating debe ser un entero no negativo.',
            ],
            'último cerrado' => [
                [array_merge(['min' => 0, 'max' => 24], $points)],
                'rules.0.max',
                'El último rango no debe tener límite superior.',
            ],
            'rango abierto intermedio' => [
                [
                    array_merge(['min' => 0, 'max' => null], $points),
                    array_merge(['min' => 1, 'max' => null], $points),
                ],
                'rules.0.max',
                'Solo el último rango puede no tener límite superior.',
            ],
            'clave desconocida' => [
                [[
                    'min' => 0,
                    'max' => null,
                    'favorite_win' => 1,
                    'underdog_win' => 1,
                    'loser_loss' => 1,
                ]],
                'rules.0',
                'El rango incluye datos que no forman parte de la tabla.',
            ],
        ];
    }

    public function test_rules_must_be_a_list(): void
    {
        $system = $this->system();

        $this->postJson(
            "/api/v1/rating-systems/{$system->id}/versions",
            ['rules' => ['min' => 0]],
            $this->authHeaders(['admin']),
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rules']);
    }

    public function test_version_number_is_unique_per_system(): void
    {
        $system = $this->system();
        $this->createDraft($system);

        $this->expectException(UniqueConstraintViolationException::class);

        RatingRuleVersion::query()->create([
            'rating_system_id' => $system->id,
            'version' => 1,
            'status' => RatingRuleVersionStatus::Draft,
            'rules' => [],
        ]);
    }

    public function test_endpoints_require_ratings_manage(): void
    {
        $system = $this->system();
        $draft = $this->createDraft($system, $this->validTable());
        $path = "/api/v1/rating-systems/{$system->id}/versions";

        $this->getJson($path)->assertUnauthorized();

        foreach (['organizer', 'player'] as $role) {
            $headers = $this->authHeaders([$role]);

            $this->getJson($path, $headers)->assertForbidden();
            $this->getJson("{$path}/{$draft['id']}", $headers)->assertForbidden();
            $this->postJson($path, ['rules' => []], $headers)->assertForbidden();
            $this->patchJson("{$path}/{$draft['id']}", ['rules' => []], $headers)->assertForbidden();
            $this->postJson("{$path}/{$draft['id']}/publish", [], $headers)->assertForbidden();
        }

        $this->assertSame(1, RatingRuleVersion::query()->count());
    }

    public function test_version_changes_are_audited_with_the_responsible_user(): void
    {
        Activity::query()->delete();

        $system = $this->system();
        $draft = $this->createDraft($system, $this->validTable());

        $created = Activity::query()
            ->where('description', AuditAction::RATING_VERSION_CREATED->value)
            ->sole();

        $user = User::query()->where('keycloak_id', 'test-subject-1')->firstOrFail();

        $this->assertSame('rating_systems', $created->log_name);
        $this->assertSame(RatingRuleVersion::class, $created->subject_type);
        $this->assertSame($user->id, $created->causer_id);
        $this->assertSame($system->id, data_get($created->properties, 'summary.rating_system_id'));
        $this->assertSame('Rating del club', data_get($created->properties, 'summary.rating_system_name'));
        $this->assertSame(1, data_get($created->properties, 'summary.version'));
        $this->assertSame($this->validTable(), data_get($created->properties, 'new.rules'));

        $this->getJson(
            '/api/v1/audit-logs?subject_type=rating_version&subject_id='.$draft['id'],
            $this->authHeaders(['admin']),
        )
            ->assertOk()
            ->assertJsonPath('data.0.action', 'rating_version.created')
            ->assertJsonPath('data.0.action_label', 'Creación de versión de reglas')
            ->assertJsonPath('data.0.category_label', 'Sistemas de rating')
            ->assertJsonPath('data.0.subject.type', 'rating_version')
            ->assertJsonPath('data.0.subject.label', 'Versión 1 · Rating del club');

        $this->patchJson(
            "/api/v1/rating-systems/{$system->id}/versions/{$draft['id']}",
            ['rules' => [
                ['min' => 0, 'max' => null, 'favorite_win' => 8, 'underdog_win' => 8],
            ]],
            $this->authHeaders(['admin']),
        )->assertOk();

        $updated = Activity::query()
            ->where('description', AuditAction::RATING_VERSION_UPDATED->value)
            ->sole();

        $this->assertSame(['rules'], data_get($updated->properties, 'summary.changed_fields'));
        $this->assertSame($user->id, $updated->causer_id);
        $this->assertSame(12, data_get($updated->properties, 'old.rules.0.favorite_win'));
        $this->assertSame(8, data_get($updated->properties, 'new.rules.0.favorite_win'));

        $this->postJson(
            "/api/v1/rating-systems/{$system->id}/versions/{$draft['id']}/publish",
            [],
            $this->authHeaders(['admin']),
        )->assertOk();

        $published = Activity::query()
            ->where('description', AuditAction::RATING_VERSION_PUBLISHED->value)
            ->sole();

        $this->assertSame('draft', data_get($published->properties, 'old.status'));
        $this->assertSame('published', data_get($published->properties, 'new.status'));
        $this->assertSame($user->id, $published->causer_id);
        $this->assertSame(1, data_get($published->properties, 'context.version'));
    }
}
