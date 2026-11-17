<?php

declare(strict_types=1);

namespace ReinfyTeam\FakeWaterdogTransfer\Config;

use pocketmine\plugin\PluginBase;

final class TransferConfig{
    public function __construct(
        private readonly PluginBase $plugin,
    ){}

    public function getMaxConcurrentTransfers() : int{
        return max(
            1,
            min(100, (int) $this->plugin->getConfig()->get("max-concurrent-transfers", 15))
        );
    }

    public function getConfiguredChunkRadius() : int{
        return max(1, min(5, (int) $this->plugin->getConfig()->get("chunk-radius", 2)));
    }

    public function getReturnDelayTicks() : int{
        return max(1, min(100, (int) $this->plugin->getConfig()->get("return-delay-ticks", 1)));
    }

    public function getTransferTimeoutSeconds() : int{
        return max(5, min(300, (int) $this->plugin->getConfig()->get("transfer-timeout-seconds", 30)));
    }

    public function getTransferChunksPerTick() : int{
        return max(4, min(128, (int) $this->plugin->getConfig()->get("transfer-chunks-per-tick", 8)));
    }

    public function getForcedChunkRadius() : int{
        return max(1, min(32, (int) $this->plugin->getConfig()->get("forced-chunk-radius", 8)));
    }

    public function getForcedChunksPerTick() : int{
        return max(4, min(128, (int) $this->plugin->getConfig()->get("forced-chunks-per-tick", 32)));
    }

    public function getForceWorldChunkLoading() : bool{
        return (bool) $this->plugin->getConfig()->get("force-world-chunk-loading", true);
    }

    public function getTemporaryViewDistanceBoost() : bool{
        return (bool) $this->plugin->getConfig()->get("temporary-view-distance-boost", true);
    }

    public function getWaitForFullViewDistance() : bool{
        return (bool) $this->plugin->getConfig()->get("wait-for-full-view-distance", true);
    }

    public function getKickStuckPlayers() : bool{
        return (bool) $this->plugin->getConfig()->get("kick-stuck-players", true);
    }

    public function getKickMessage() : string{
        return (string) $this->plugin->getConfig()->get(
            "kick-message",
            "§cYou got stuck during world transfer. Please reconnect."
        );
    }

    public function getKickStuckAfterSeconds() : int{
        return max(5, min(300, (int) $this->plugin->getConfig()->get("kick-stuck-after-seconds", 15)));
    }
}
