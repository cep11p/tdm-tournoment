<?php

namespace App\Support\Competition;

use App\Enums\CompetitionEntryStatus;
use App\Enums\CompetitionType;
use App\Models\CompetitionEntry;
use App\Models\CompetitionEntryMember;

final class CompetitionEntryAvailabilityResolver
{
    /**
     * @return array{kind: string, label: string, present: int, total: int}
     */
    public static function forEntry(CompetitionEntry $entry, CompetitionType $type): array
    {
        $entry->loadMissing('members');

        $status = $entry->status instanceof CompetitionEntryStatus
            ? $entry->status
            : CompetitionEntryStatus::from((string) $entry->status);

        $members = $entry->members;
        $present = $members
            ->filter(fn (CompetitionEntryMember $member): bool => $member->isCheckedIn())
            ->count();
        $total = $members->count();

        if ($status !== CompetitionEntryStatus::Active) {
            return [
                'kind' => 'inactive',
                'label' => $status === CompetitionEntryStatus::Withdrawn
                    ? 'Retirado'
                    : 'Descalificado',
                'present' => $present,
                'total' => $total,
            ];
        }

        return match ($type) {
            CompetitionType::Singles => self::forSingles($present, $total),
            CompetitionType::Doubles => self::forDoubles($present, $total),
            CompetitionType::Team => self::forTeam($present, $total),
        };
    }

    /**
     * @return array{kind: string, label: string, present: int, total: int}
     */
    private static function forSingles(int $present, int $total): array
    {
        if ($present >= 1 && $total >= 1) {
            return [
                'kind' => 'ready',
                'label' => 'Presente',
                'present' => $present,
                'total' => $total,
            ];
        }

        return [
            'kind' => 'pending',
            'label' => 'Pendiente',
            'present' => $present,
            'total' => $total,
        ];
    }

    /**
     * @return array{kind: string, label: string, present: int, total: int}
     */
    private static function forDoubles(int $present, int $total): array
    {
        if ($total > 0 && $present === $total) {
            return [
                'kind' => 'ready',
                'label' => 'Pareja presente',
                'present' => $present,
                'total' => $total,
            ];
        }

        if ($present === 1) {
            return [
                'kind' => 'incomplete',
                'label' => 'Pareja incompleta',
                'present' => $present,
                'total' => $total,
            ];
        }

        return [
            'kind' => 'incomplete',
            'label' => 'Pendientes',
            'present' => $present,
            'total' => $total,
        ];
    }

    /**
     * @return array{kind: string, label: string, present: int, total: int}
     */
    private static function forTeam(int $present, int $total): array
    {
        $label = sprintf('%d de %d presentes', $present, $total);

        if ($total > 0 && $present === $total) {
            return [
                'kind' => 'ready',
                'label' => $label,
                'present' => $present,
                'total' => $total,
            ];
        }

        return [
            'kind' => 'partial',
            'label' => $label,
            'present' => $present,
            'total' => $total,
        ];
    }
}
