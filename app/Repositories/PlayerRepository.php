<?php

namespace App\Repositories;

use App\Models\Player;

class PlayerRepository
{
    public function getGovernmentTreasury(): Player
    {
        return Player::where('is_government', true)->firstOrFail();
    }

    public function lockForUpdate(int $playerId): Player
    {
        return Player::where('id', $playerId)->lockForUpdate()->firstOrFail();
    }

    public function updateBalance(Player $player, float $amount): bool
    {
        $player->balance += $amount;
        return $player->save();
    }
}
