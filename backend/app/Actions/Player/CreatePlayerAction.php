<?php

namespace App\Actions\Player;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Models\Player;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\AuditPlayerAttributes;
use Illuminate\Support\Facades\DB;

final class CreatePlayerAction
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function __invoke(array $payload): Player
    {
        return DB::transaction(function () use ($payload): Player {
            $player = Player::query()->create([
                ...$payload,
                'active' => $payload['active'] ?? true,
            ]);

            $player->refresh()->load(['category:id,name', 'club:id,name']);

            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::PLAYER_CREATED,
                logName: 'players',
                subject: $player,
                context: AuditContextBuilder::fromPlayer($player),
                new: AuditPlayerAttributes::snapshot($player),
                summary: [
                    'player_id' => $player->id,
                    'player_name' => AuditContextBuilder::fromPlayer($player)['player_name'],
                ],
            ));

            return $player;
        });
    }
}
