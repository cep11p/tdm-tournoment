<?php

namespace Database\Seeders\Support;

use App\Models\Player;
use Illuminate\Support\Collection;

final class DemoPlayerCatalog
{
  /**
   * @var array<int, array{first_name: string, last_name: string, nickname: string}>
   */
    private const PLAYERS = [
        1 => ['first_name' => 'Carlos', 'last_name' => 'Perez', 'nickname' => 'demo-carlos-perez'],
        2 => ['first_name' => 'Juan', 'last_name' => 'Gomez', 'nickname' => 'demo-juan-gomez'],
        3 => ['first_name' => 'Pedro', 'last_name' => 'Ruiz', 'nickname' => 'demo-pedro-ruiz'],
        4 => ['first_name' => 'Marcos', 'last_name' => 'Diaz', 'nickname' => 'demo-marcos-diaz'],
        5 => ['first_name' => 'Luis', 'last_name' => 'Lopez', 'nickname' => 'demo-luis-lopez'],
        6 => ['first_name' => 'Martin', 'last_name' => 'Castro', 'nickname' => 'demo-martin-castro'],
        7 => ['first_name' => 'Diego', 'last_name' => 'Silva', 'nickname' => 'demo-diego-silva'],
        8 => ['first_name' => 'Nicolas', 'last_name' => 'Torres', 'nickname' => 'demo-nicolas-torres'],
    ];

    /**
     * @var array<int, array{first_name: string, last_name: string, nickname: string, seed: int}>
     */
    public const DOUBLES_PAIRS = [
        [
            'seed' => 1,
            'nicknames' => ['demo-carlos-perez', 'demo-juan-gomez'],
        ],
        [
            'seed' => 2,
            'nicknames' => ['demo-pedro-ruiz', 'demo-marcos-diaz'],
        ],
        [
            'seed' => 3,
            'nicknames' => ['demo-luis-lopez', 'demo-martin-castro'],
        ],
        [
            'seed' => 4,
            'nicknames' => ['demo-diego-silva', 'demo-nicolas-torres'],
        ],
    ];

    /**
     * @return array<int, array{first_name: string, last_name: string, nickname: string}>
     */
    public static function definitions(): array
    {
        return self::PLAYERS;
    }

  /**
   * @return Collection<int, Player>
   */
    public static function all(): Collection
    {
        return collect(self::PLAYERS)
            ->map(fn (array $definition): Player => Player::query()
                ->where('nickname', $definition['nickname'])
                ->firstOrFail());
    }

    public static function bySeed(int $seed): Player
    {
        $definition = self::PLAYERS[$seed] ?? null;

        if ($definition === null) {
            throw new \InvalidArgumentException(sprintf('No existe jugador demo con seed %d.', $seed));
        }

        return Player::query()
            ->where('nickname', $definition['nickname'])
            ->firstOrFail();
    }

    public static function byNickname(string $nickname): Player
    {
        return Player::query()
            ->where('nickname', $nickname)
            ->firstOrFail();
    }

    public static function nicknameForSeed(int $seed): string
    {
        return self::PLAYERS[$seed]['nickname']
            ?? throw new \InvalidArgumentException(sprintf('No existe jugador demo con seed %d.', $seed));
    }

    public static function seedForNickname(string $nickname): int
    {
        foreach (self::PLAYERS as $seed => $definition) {
            if ($definition['nickname'] === $nickname) {
                return $seed;
            }
        }

        throw new \InvalidArgumentException(sprintf('Nickname demo desconocido: %s', $nickname));
    }

    public static function seedForPlayer(Player $player): int
    {
        return self::seedForNickname((string) $player->nickname);
    }

    /**
     * @param  list<string>  $nicknames
     * @return list<Player>
     */
    public static function playersByNicknames(array $nicknames): array
    {
        return array_map(
            fn (string $nickname): Player => self::byNickname($nickname),
            $nicknames,
        );
    }
}
