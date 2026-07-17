<?php

namespace App\Enums;

use App\Models\Bracket;
use App\Models\Competition;
use App\Models\Game;
use App\Models\Group;
use App\Models\Player;
use App\Models\Tournament;
use Illuminate\Database\Eloquent\Model;

enum AuditSubjectType: string
{
    case Tournament = 'tournament';
    case Competition = 'competition';
    case Player = 'player';
    case Group = 'group';
    case Bracket = 'bracket';
    case Game = 'game';

    /**
     * @return class-string<Model>
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::Tournament => Tournament::class,
            self::Competition => Competition::class,
            self::Player => Player::class,
            self::Group => Group::class,
            self::Bracket => Bracket::class,
            self::Game => Game::class,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Tournament => 'Torneo',
            self::Competition => 'Competencia',
            self::Player => 'Jugador',
            self::Group => 'Grupo',
            self::Bracket => 'Llave',
            self::Game => 'Partido',
        };
    }

    public static function fromModel(?Model $model): ?self
    {
        if ($model === null) {
            return null;
        }

        return match ($model::class) {
            Tournament::class => self::Tournament,
            Competition::class => self::Competition,
            Player::class => self::Player,
            Group::class => self::Group,
            Bracket::class => self::Bracket,
            Game::class => self::Game,
            default => null,
        };
    }

    public static function fromSubjectType(?string $subjectType): ?self
    {
        if ($subjectType === null || $subjectType === '') {
            return null;
        }

        return match ($subjectType) {
            Tournament::class => self::Tournament,
            Competition::class => self::Competition,
            Player::class => self::Player,
            Group::class => self::Group,
            Bracket::class => self::Bracket,
            Game::class => self::Game,
            default => self::tryFrom($subjectType),
        };
    }

    /**
     * @return list<string>
     */
    public static function publicValues(): array
    {
        return array_map(
            static fn (self $type): string => $type->value,
            self::cases(),
        );
    }
}
