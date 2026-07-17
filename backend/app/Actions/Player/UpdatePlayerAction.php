<?php

namespace App\Actions\Player;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Models\Player;
use App\Support\Audit\AuditChangeResolver;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\AuditPlayerAttributes;
use Illuminate\Support\Facades\DB;

final class UpdatePlayerAction
{
    /**
     * @var list<string>
     */
    private const AUDITABLE_FIELDS = [
        'first_name',
        'last_name',
        'nickname',
        'category_id',
        'club_id',
        'active',
    ];

    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function __invoke(Player $player, array $payload): Player
    {
        return DB::transaction(function () use ($player, $payload): Player {
            $wasActive = (bool) $player->active;

            $player->fill($payload);

            $changes = AuditChangeResolver::resolve($player, self::AUDITABLE_FIELDS);

            if ($changes === null) {
                return $player;
            }

            $changes = AuditPlayerAttributes::enrichRelationNames($changes);

            $player->save();
            $player->refresh()->load(['category:id,name', 'club:id,name']);

            $isDeactivation = $wasActive
                && array_key_exists('active', $changes['new'])
                && $changes['new']['active'] === false;

            $this->auditLogger->log(new AuditEntry(
                action: $isDeactivation
                    ? AuditAction::PLAYER_DEACTIVATED
                    : AuditAction::PLAYER_UPDATED,
                logName: 'players',
                subject: $player,
                context: AuditContextBuilder::fromPlayer($player),
                old: $changes['old'],
                new: $changes['new'],
                summary: [
                    'player_id' => $player->id,
                    'player_name' => AuditContextBuilder::fromPlayer($player)['player_name'],
                    'changed_fields' => array_keys($changes['new']),
                ],
            ));

            return $player;
        });
    }
}
