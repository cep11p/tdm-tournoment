<?php

namespace App\Support\Audit;

use App\Enums\AuditSubjectType;
use App\Models\Bracket;
use App\Models\Competition;
use App\Models\Game;
use App\Models\Group;
use App\Models\Player;
use App\Models\Tournament;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

final class AuditLogSubjectPresenter
{
    /**
     * @return array{
     *     type: string,
     *     id: int|null,
     *     label: string,
     *     exists: bool
     * }|null
     */
    public static function present(Activity $activity): ?array
    {
        $properties = $activity->properties?->toArray() ?? [];
        $context = data_get($properties, 'context', []);
        $summary = data_get($properties, 'summary', []);

        $subject = $activity->relationLoaded('subject') ? $activity->subject : null;
        $type = AuditSubjectType::fromModel($subject)
            ?? AuditSubjectType::fromSubjectType($activity->subject_type);

        if ($type === null && $activity->subject_id !== null) {
            $type = self::inferTypeFromContext($context);
        }

        if ($type === null && $activity->subject_id === null) {
            return null;
        }

        $publicType = $type?->value ?? 'unknown';
        $id = $activity->subject_id ?? self::inferIdFromContext($context, $type);

        $label = self::resolveLabel($subject, $type, $context, $summary, $publicType, $id);
        $exists = $subject !== null;

        return [
            'type' => $publicType,
            'id' => $id !== null ? (int) $id : null,
            'label' => $label,
            'exists' => $exists,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $summary
     */
    private static function resolveLabel(
        ?Model $subject,
        ?AuditSubjectType $type,
        array $context,
        array $summary,
        string $publicType,
        mixed $id,
    ): string {
        if ($subject instanceof Tournament) {
            return $subject->name;
        }

        if ($subject instanceof Competition) {
            return $subject->name;
        }

        if ($subject instanceof Player) {
            return self::playerDisplayName($subject) ?? "Jugador #{$subject->id}";
        }

        if ($subject instanceof Group) {
            return $subject->name ?? "Grupo #{$subject->id}";
        }

        if ($subject instanceof Bracket) {
            return self::bracketLabel($subject, $context);
        }

        if ($subject instanceof Game) {
            return self::gameLabel($context, $summary, $subject->id);
        }

        $historical = self::historicalLabel($type, $context, $summary);

        if ($historical !== null) {
            return $historical;
        }

        if ($type !== null && $id !== null) {
            return sprintf('%s #%s', $type->label(), $id);
        }

        return 'Entidad eliminada';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function bracketLabel(Bracket $bracket, array $context): string
    {
        $competitionName = data_get($context, 'competition_name');

        if (is_string($competitionName) && $competitionName !== '') {
            return "Llave de {$competitionName}";
        }

        return "Llave #{$bracket->id}";
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $summary
     */
    private static function gameLabel(array $context, array $summary, int $gameId): string
    {
        $player1 = data_get($context, 'player1_name') ?? data_get($summary, 'player1_name');
        $player2 = data_get($context, 'player2_name') ?? data_get($summary, 'player2_name');

        if (is_string($player1) && is_string($player2) && $player1 !== '' && $player2 !== '') {
            return "{$player1} vs {$player2}";
        }

        return "Partido #{$gameId}";
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $summary
     */
    private static function historicalLabel(?AuditSubjectType $type, array $context, array $summary): ?string
    {
        return match ($type) {
            AuditSubjectType::Tournament => data_get($context, 'tournament_name'),
            AuditSubjectType::Competition => data_get($context, 'competition_name'),
            AuditSubjectType::Player => data_get($context, 'player_name'),
            AuditSubjectType::Group => data_get($context, 'group_name'),
            AuditSubjectType::Bracket => self::historicalBracketLabel($context),
            AuditSubjectType::Game => self::gameLabel($context, $summary, (int) (data_get($context, 'game_id') ?? 0)),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function historicalBracketLabel(array $context): ?string
    {
        $competitionName = data_get($context, 'competition_name');

        if (is_string($competitionName) && $competitionName !== '') {
            return "Llave de {$competitionName}";
        }

        $bracketId = data_get($context, 'bracket_id');

        return $bracketId !== null ? "Llave #{$bracketId}" : null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function inferTypeFromContext(array $context): ?AuditSubjectType
    {
        if (data_get($context, 'game_id') !== null) {
            return AuditSubjectType::Game;
        }

        if (data_get($context, 'group_id') !== null) {
            return AuditSubjectType::Group;
        }

        if (data_get($context, 'bracket_id') !== null) {
            return AuditSubjectType::Bracket;
        }

        if (data_get($context, 'player_id') !== null && data_get($context, 'competition_id') === null) {
            return AuditSubjectType::Player;
        }

        if (data_get($context, 'competition_id') !== null) {
            return AuditSubjectType::Competition;
        }

        if (data_get($context, 'tournament_id') !== null) {
            return AuditSubjectType::Tournament;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function inferIdFromContext(array $context, ?AuditSubjectType $type): ?int
    {
        $key = match ($type) {
            AuditSubjectType::Tournament => 'tournament_id',
            AuditSubjectType::Competition => 'competition_id',
            AuditSubjectType::Player => 'player_id',
            AuditSubjectType::Group => 'group_id',
            AuditSubjectType::Bracket => 'bracket_id',
            AuditSubjectType::Game => 'game_id',
            default => null,
        };

        if ($key === null) {
            return null;
        }

        $value = data_get($context, $key);

        return $value !== null ? (int) $value : null;
    }

    private static function playerDisplayName(Player $player): ?string
    {
        $name = trim(sprintf('%s %s', $player->first_name, $player->last_name));

        return $name !== '' ? $name : null;
    }
}
