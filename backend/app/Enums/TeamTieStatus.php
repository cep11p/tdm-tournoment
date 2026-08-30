<?php

namespace App\Enums;

enum TeamTieStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case InProgress = 'in_progress';
    case Finished = 'finished';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Ready => 'Listo',
            self::InProgress => 'En curso',
            self::Finished => 'Finalizado',
            self::Cancelled => 'Cancelado',
        };
    }
}
