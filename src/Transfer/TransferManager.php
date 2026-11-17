<?php

declare(strict_types=1);

namespace ReinfyTeam\FakeWaterdogTransfer\Transfer;

use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\player\Player;
use pocketmine\player\UsedChunkStatus;
use pocketmine\scheduler\ClosureTask;
use pocketmine\scheduler\TaskHandler;
use pocketmine\world\World;
use ReinfyTeam\FakeWaterdogTransfer\Chunk\ChunkForcer;
use ReinfyTeam\FakeWaterdogTransfer\Config\TransferConfig;
use ReinfyTeam\FakeWaterdogTransfer\Transfer\TransferScreen;
use function array_key_first;
use function count;
use function floor;
use function max;
use function min;
use const M_SQRT2;

/**
 * Coordinates all fake-dimension transfers with a single server tick loop.
 *
 * Having one repeating task instead of one per player keeps overhead near-zero
 * even with 100+ concurrent players transferring.
 */
final class TransferManager{
    /** @var array<string, TransferState> Player name => active transfer state */
    private array $transferring = [];

    /** @var array<string, Player> Player name => queued player */
    private array $transferQueue = [];

    /** @var array<string, DimensionIds::*> World folder => Bedrock dimension ID */
    private array $applicableWorlds = [];

    private ?TaskHandler $tickTask = null;

    public function __construct(
        private readonly TransferConfig $config,
        private readonly ChunkForcer $chunkForcer,
        private readonly \Closure $schedulerGetter,
    ){}

    public function onEnable() : void{
        $this->tickTask = ($this->schedulerGetter())->scheduleRepeatingTask(
            new ClosureTask([$this, "tick"]),
            1
        );
    }

    public function onDisable() : void{
        foreach(array_keys($this->transferring) as $name){
            $this->finishTransfer($name, null, true);
        }
        $this->transferQueue = [];
        $this->tickTask?->cancel();
    }

    /**
     * Handles EntityTeleportEvent (MONITOR priority). Deferred one tick so
     * other listeners can cancel/modify the destination first.
     */
    public function handleTeleport(EntityTeleportEvent $event) : void{
        $player = $event->getEntity();

        if(!$player instanceof Player || $event->isCancelled()){
            return;
        }

        $fromWorld = $event->getFrom()->getWorld();
        $toWorld = $event->getTo()->getWorld();

        if($fromWorld === $toWorld || $fromWorld->getFolderName() === $toWorld->getFolderName()){
            return;
        }

        ($this->schedulerGetter())->scheduleDelayedTask(
            new ClosureTask(function() use ($player, $toWorld) : void{
                if(!$player->isConnected() || !$player->isAlive()){
                    return;
                }
                if($player->getWorld() !== $toWorld){
                    return;
                }
                $this->queueOrStartTransfer($player);
            }),
            1
        );
    }

    public function handlePlayerQuit(PlayerQuitEvent $event) : void{
        $name = $event->getPlayer()->getName();
        unset($this->transferQueue[$name]);
        $this->finishTransfer($name);
    }

    /**
     * Main tick: processes the queue and polls chunk readiness for all active transfers.
     */
    public function tick() : void{
        $this->processTransferQueue();

        $names = array_keys($this->transferring);
        foreach($names as $name){
            if(!isset($this->transferring[$name])){
                continue;
            }
            $this->pollRequiredChunks($name, $this->transferring[$name]);
        }
    }

    private function queueOrStartTransfer(Player $player) : void{
        $name = $player->getName();

        if(!$player->isConnected() || !$player->isAlive()){
            return;
        }

        if(isset($this->transferring[$name])){
            $activeState = $this->transferring[$name];
            if($activeState->world === $player->getWorld()){
                return;
            }
            $this->finishTransfer($name, $activeState, true);
        }

        if(count($this->transferring) >= $this->config->getMaxConcurrentTransfers()){
            $this->transferQueue[$name] = $player;
            return;
        }

        unset($this->transferQueue[$name]);
        $this->startTransfer($player);
    }

    private function startTransfer(Player $player) : void{
        if(!$player->isConnected() || !$player->isAlive()){
            return;
        }

        $name = $player->getName();
        $world = $player->getWorld();
        $position = $player->getPosition();

        $chunkX = $position->getFloorX() >> 4;
        $chunkZ = $position->getFloorZ() >> 4;

        $clientViewDistance = max(1, $player->getViewDistance());
        $allowedViewDistance = max(
            1,
            $player->getServer()->getAllowedViewDistance($clientViewDistance)
        );

        $requiredChunkHashes = [];

        if($this->config->getWaitForFullViewDistance()){
            $selector = new \pocketmine\player\ChunkSelector();
            foreach($selector->selectChunks($allowedViewDistance, $chunkX, $chunkZ) as $hash){
                $requiredChunkHashes[] = $hash;
            }
        }else{
            $viewSafeRadius = (int) floor($allowedViewDistance / M_SQRT2);
            $chunkRadius = min($this->config->getConfiguredChunkRadius(), $viewSafeRadius);

            for($dx = -$chunkRadius; $dx <= $chunkRadius; ++$dx){
                for($dz = -$chunkRadius; $dz <= $chunkRadius; ++$dz){
                    $requiredChunkHashes[] = World::chunkHash($chunkX + $dx, $chunkZ + $dz);
                }
            }
        }

        $originalChunksPerTick = $this->applyTemporaryChunkSendBoost($player);

        // Force fast chunk loading before creating state so chunks start moving immediately.
        [$originalViewDistance, $viewDistanceBoosted] = $this->chunkForcer->force($player);

        $state = new TransferState(
            $player,
            $world,
            $requiredChunkHashes,
            $originalChunksPerTick
        );
        $state->originalViewDistance = $originalViewDistance;
        $state->viewDistanceBoosted = $viewDistanceBoosted;

        $this->transferring[$name] = $state;

        // Timeout stays active through both chunk delivery and return delay.
        $state->timeoutTask = ($this->schedulerGetter())->scheduleDelayedTask(
            new ClosureTask(function() use ($name, $state) : void{
                if(!$this->isCurrentTransfer($name, $state)){
                    return;
                }

                $player = $state->player;
                $player->getServer()->getLogger()->warning(
                    "Chunk-send wait timed out for {$name}; restoring the destination dimension."
                );

                if($this->config->getKickStuckPlayers()){
                    $this->kickStuckPlayer($player);
                }else{
                    $this->finishTransfer($name, $state, true);
                }
            }),
            $this->config->getTransferTimeoutSeconds() * 20
        );

        TransferScreen::showFakeNetherScreen($player);
    }

    /**
     * Temporarily increases chunksPerTick for the duration of the transfer.
     */
    private function applyTemporaryChunkSendBoost(Player $player) : ?int{
        $prop = $this->getReflectionProperty(Player::class, "chunksPerTick");
        if($prop === null){
            return null;
        }

        try{
            $original = (int) $prop->getValue($player);
            $boosted = max($original, $this->config->getTransferChunksPerTick());
            if($boosted !== $original){
                $prop->setValue($player, $boosted);
            }
            return $original;
        }catch(\Throwable $e){
            $player->getServer()->getLogger()->warning(
                "Could not apply temporary chunk-send boost to {$player->getName()}: " . $e->getMessage()
            );
            return null;
        }
    }

    /**
     * Polls a single transfer: checks if all required chunks are SENT.
     */
    private function pollRequiredChunks(string $name, TransferState $state) : void{
        if(!$this->isCurrentTransfer($name, $state)){
            return;
        }

        $player = $state->player;

        if(!$player->isConnected() || !$player->isAlive()){
            $this->finishTransfer($name, $state);
            return;
        }

        if($player->getWorld() !== $state->world){
            $this->finishTransfer($name, $state, true);
            return;
        }

        $usedChunks = $player->getUsedChunks();

        // --- Proactive stuck detection ---
        $sentCount = 0;
        foreach($state->requiredChunkHashes as $chunkHash){
            if(($usedChunks[$chunkHash] ?? null) === UsedChunkStatus::SENT){
                $sentCount++;
            }
        }

        if($sentCount > $state->lastSentCount){
            // Progress made: new chunks reached SENT
            $state->lastSentCount = $sentCount;
            $state->ticksWithoutProgress = 0;
        }elseif($sentCount < count($state->requiredChunkHashes)){
            // No progress but not all chunks sent yet
            $state->ticksWithoutProgress++;

            $maxIdleTicks = $this->config->getKickStuckAfterSeconds() * 20;
            if($state->ticksWithoutProgress >= $maxIdleTicks){
                $player->getServer()->getLogger()->warning(
                    "Kicking {$name} — no chunk transfer progress for " . $this->config->getKickStuckAfterSeconds() . " seconds."
                );
                $this->kickStuckPlayer($player);
                return;
            }
        }
        // ---------------------------------

        foreach($state->requiredChunkHashes as $chunkHash){
            if(($usedChunks[$chunkHash] ?? null) !== UsedChunkStatus::SENT){
                return;
            }
        }

        if($state->returnScheduled){
            return;
        }

        $state->returnScheduled = true;

        $state->returnTask = ($this->schedulerGetter())->scheduleDelayedTask(
            new ClosureTask(function() use ($name, $state) : void{
                if(!$this->isCurrentTransfer($name, $state)){
                    return;
                }

                $player = $state->player;

                if(!$player->isConnected() || !$player->isAlive()){
                    $this->finishTransfer($name, $state);
                    return;
                }

                $this->finishTransfer($name, $state, true);
            }),
            $this->config->getReturnDelayTicks()
        );
    }

    private function finishTransfer(
        string $name,
        ?TransferState $expected = null,
        bool $restoreDimension = false
    ) : void{
        $state = $this->transferring[$name] ?? null;

        if($state === null || ($expected !== null && $state !== $expected)){
            return;
        }

        unset($this->transferring[$name]);

        $state->timeoutTask?->cancel();
        $state->returnTask?->cancel();

        $player = $state->player;

        // Restore chunksPerTick to the value before the temporary boost.
        $originalChunksPerTickProp = $this->getReflectionProperty(Player::class, "chunksPerTick");
        if($state->originalChunksPerTick !== null && $originalChunksPerTickProp !== null){
            try{
                $originalChunksPerTickProp->setValue($player, $state->originalChunksPerTick);
            }catch(\Throwable $e){
                $player->getServer()->getLogger()->warning(
                    "Could not restore chunk-send rate for {$name}: " . $e->getMessage()
                );
            }
        }

        // Restore original view distance if it was boosted.
        $this->chunkForcer->restoreViewDistance(
            $player,
            $state->originalViewDistance,
            $state->viewDistanceBoosted
        );

        if($restoreDimension && $player->isConnected() && $player->isAlive()){
            $dimensionId = TransferScreen::getDimensionIdForWorld(
                $this->applicableWorlds,
                $player->getWorld()->getFolderName()
            );
            TransferScreen::restoreTargetDimension($player, $dimensionId);
        }
    }

    /**
     * Kicks a player who is stuck at the fake transfer screen.
     * Player::kick() internally sends a DisconnectPacket to the client.
     */
    private function kickStuckPlayer(Player $player) : void{
        $name = $player->getName();

        // Clean up transfer state first (don't restore dimension, just release)
        $this->finishTransfer($name, null, false);

        if(!$player->isConnected()){
            return;
        }

        $player->kick($this->config->getKickMessage(), true);
    }

    private function processTransferQueue() : void{
        while(
            count($this->transferring) < $this->config->getMaxConcurrentTransfers()
            && $this->transferQueue !== []
        ){
            $name = array_key_first($this->transferQueue);

            if($name === null){
                return;
            }

            $player = $this->transferQueue[$name];
            unset($this->transferQueue[$name]);

            if(!$player->isConnected() || !$player->isAlive()){
                continue;
            }

            if(isset($this->transferring[$name])){
                continue;
            }

            $this->startTransfer($player);
        }
    }

    private function isCurrentTransfer(string $name, TransferState $state) : bool{
        return isset($this->transferring[$name])
            && $this->transferring[$name] === $state;
    }

    /**
     * Maps a world folder to the dimension ID used in that world's chunk cache.
     *
     * @param DimensionIds::* $dimensionId
     */
    public function applyToWorld(string $worldFolderName, int $dimensionId) : void{
        $this->applicableWorlds[$worldFolderName] = $dimensionId;
    }

    /**
     * Removes the custom mapping and restores the OVERWORLD chunk-cache ID.
     */
    public function unapplyFromWorld(string $worldFolderName) : void{
        unset($this->applicableWorlds[$worldFolderName]);
    }

    // --- Reflection helper -------------------------------------------------

    private function getReflectionProperty(string $class, string $property): ?\ReflectionProperty{
        static $cache = [];

        $key = $class . "::" . $property;
        if(isset($cache[$key])){
            return $cache[$key];
        }

        try{
            $ref = new \ReflectionProperty($class, $property);
            $cache[$key] = $ref;
            return $ref;
        }catch(\ReflectionException $e){
            $cache[$key] = null;
            return null;
        }
    }
}
