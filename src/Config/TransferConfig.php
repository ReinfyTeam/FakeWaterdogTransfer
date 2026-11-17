<?php

declare(strict_types=1);

namespace ReinfyTeam\FakeWaterdogTransfer\Config;

use pocketmine\plugin\PluginBase;

final class TransferConfig{
    public function __construct(
        private readonly PluginBase $plugin,
    ){}

    public function getMaxConcurrentTransfers() : int{
        return $this->getBoundedInt("max-concurrent-transfers", 15, 1, 100);
    }

    public function getConfiguredChunkRadius() : int{
        return $this->getBoundedInt("chunk-radius", 2, 1, 5);
    }

    public function getReturnDelayTicks() : int{
        return $this->getBoundedInt("return-delay-ticks", 1, 1, 100);
    }

    public function getTransferTimeoutSeconds() : int{
        return $this->getBoundedInt("transfer-timeout-seconds", 30, 5, 300);
    }

    public function getTransferChunksPerTick() : int{
        return $this->getBoundedInt("transfer-chunks-per-tick", 8, 4, 128);
    }

    public function getForcedChunkRadius() : int{
        return $this->getBoundedInt("forced-chunk-radius", 8, 1, 32);
    }

    public function getForcedChunksPerTick() : int{
        return $this->getBoundedInt("forced-chunks-per-tick", 32, 4, 128);
    }

    public function getForceWorldChunkLoading() : bool{
        return $this->getBoolean("force-world-chunk-loading", true);
    }

    public function getTemporaryViewDistanceBoost() : bool{
        return $this->getBoolean("temporary-view-distance-boost", true);
    }

    public function getWaitForFullViewDistance() : bool{
        return $this->getBoolean("wait-for-full-view-distance", true);
    }

    public function getKickStuckPlayers() : bool{
        return $this->getBoolean("kick-stuck-players", true);
    }

    public function getKickMessage() : string{
        $default = "§cYou got stuck during world transfer. Please reconnect.";
        $value = $this->plugin->getConfig()->get("kick-message", $default);

        return is_string($value) ? $value : $default;
    }

    public function getKickStuckAfterSeconds() : int{
        return $this->getBoundedInt("kick-stuck-after-seconds", 15, 5, 300);
    }

    private function getBoundedInt(string $key, int $default, int $minimum, int $maximum) : int{
        $value = $this->plugin->getConfig()->get($key, $default);
        if(!is_numeric($value)){
            return $default;
        }

        return max($minimum, min($maximum, (int) $value));
    }

    private function getBoolean(string $key, bool $default) : bool{
        $value = $this->plugin->getConfig()->get($key, $default);

        return is_bool($value) ? $value : $default;
    }
}
