<?php

namespace Tests\Unit\Group;

use App\Support\Group\SeededGroupEntriesGuard;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SeededGroupEntriesGuardTest extends TestCase
{
    public function test_empty_seeded_ids_are_valid(): void
    {
        $this->assertSame([], SeededGroupEntriesGuard::ensureValid([1, 2, 3, 4], [], 2));
    }

    public function test_returns_seeded_ids_in_the_same_order(): void
    {
        $this->assertSame(
            [4, 1],
            SeededGroupEntriesGuard::ensureValid([1, 2, 3, 4], [4, 1], 2),
        );
    }

    public function test_rejects_more_seeds_than_groups(): void
    {
        try {
            SeededGroupEntriesGuard::ensureValid([1, 2, 3, 4], [1, 2, 3], 2);
            $this->fail('Se esperaba ValidationException');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('seeded_entry_ids', $exception->errors());
            $this->assertSame(
                SeededGroupEntriesGuard::tooManyMessage(3, 2),
                $exception->errors()['seeded_entry_ids'][0],
            );
        }
    }

    public function test_rejects_duplicate_seeded_ids(): void
    {
        try {
            SeededGroupEntriesGuard::ensureValid([1, 2, 3, 4], [1, 1], 2);
            $this->fail('Se esperaba ValidationException');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('seeded_entry_ids', $exception->errors());
        }
    }

    public function test_rejects_ids_outside_the_eligible_set(): void
    {
        try {
            SeededGroupEntriesGuard::ensureValid([1, 2, 3, 4], [99], 2);
            $this->fail('Se esperaba ValidationException');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('seeded_entry_ids', $exception->errors());
        }
    }
}
