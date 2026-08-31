<?php

namespace Tests\Unit\TeamTie;

use App\Enums\TeamTieModality;
use App\Models\TeamTieFormat;
use App\Models\TeamTieFormatSlot;
use App\Support\TeamTie\TeamTieFormatValidator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TeamTieFormatValidatorTest extends TestCase
{
    /**
     * @return array<string, array{int, int}>
     */
    public static function validVictoriesRequiredProvider(): array
    {
        return [
            '1 slot, required 1' => [1, 1],
            '3 slots, required 2' => [3, 2],
            '4 slots, required 3' => [4, 3],
            '5 slots, required 3' => [5, 3],
        ];
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function invalidVictoriesRequiredProvider(): array
    {
        return [
            '3 slots, required 1' => [3, 1],
            '5 slots, required 2' => [5, 2],
            'required 0' => [3, 0],
            'required greater than slots' => [5, 6],
        ];
    }

    #[DataProvider('validVictoriesRequiredProvider')]
    public function test_accepts_victories_required_with_strict_majority(int $slotsCount, int $victoriesRequired): void
    {
        $format = $this->createFormatWithSlots($slotsCount, $victoriesRequired);

        TeamTieFormatValidator::ensureValid($format);

        $this->assertTrue(true);
    }

    #[DataProvider('invalidVictoriesRequiredProvider')]
    public function test_rejects_invalid_victories_required(int $slotsCount, int $victoriesRequired): void
    {
        $format = $this->createFormatWithSlots($slotsCount, $victoriesRequired);

        $this->expectException(ValidationException::class);

        TeamTieFormatValidator::ensureValid($format);
    }

    public function test_rejects_format_without_slots(): void
    {
        $format = TeamTieFormat::query()->create([
            'name' => 'Sin partidos',
            'victories_required' => 1,
            'active' => true,
        ]);

        $this->expectException(ValidationException::class);

        TeamTieFormatValidator::ensureValid($format->fresh());
    }

    public function test_counts_slots_from_database_when_relation_is_not_loaded(): void
    {
        $format = $this->createFormatWithSlots(3, 1);

        $this->expectException(ValidationException::class);

        TeamTieFormatValidator::ensureValid(TeamTieFormat::query()->findOrFail($format->id));
    }

    private function createFormatWithSlots(int $slotsCount, int $victoriesRequired): TeamTieFormat
    {
        $format = TeamTieFormat::query()->create([
            'name' => "Formato {$slotsCount}x{$victoriesRequired}",
            'victories_required' => $victoriesRequired,
            'active' => true,
        ]);

        for ($slotOrder = 1; $slotOrder <= $slotsCount; $slotOrder++) {
            TeamTieFormatSlot::query()->create([
                'team_tie_format_id' => $format->id,
                'slot_order' => $slotOrder,
                'modality' => TeamTieModality::Singles,
            ]);
        }

        return $format->fresh('slots');
    }
}
