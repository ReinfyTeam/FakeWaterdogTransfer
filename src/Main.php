<?php

/*
 *
 *  ____           _            __           _____
 * |  _ \    ___  (_)  _ __    / _|  _   _  |_   _|   ___    __ _   _ __ ___
 * | |_) |  / _ \ | | | '_ \  | |_  | | | |   | |    / _ \  / _` | | '_ ` _ \
 * |  _ <  |  __/ | | | | | | |  _| | |_| |   | |   |  __/ | (_| | | | | | | |
 * |_| \_\  \___| |_| |_| |_| |_|    \__, |   |_|    \___|  \__,_| |_| |_| |_|
 *                                   |___/
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author ReinfyTeam
 * @link https://github.com/ReinfyTeam/
 *
 *
 */

declare(strict_types=1);

namespace ReinfyTeam\FakeWaterdogTransfer;

use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\event\EventPriority;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\LowMemoryEvent;
use pocketmine\event\world\WorldLoadEvent;
use pocketmine\network\mcpe\cache\ChunkCache;
use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\network\mcpe\protocol\ChangeDimensionPacket;
use pocketmine\network\mcpe\protocol\PlayStatusPacket;
use pocketmine\network\mcpe\protocol\PlayerActionPacket;
use pocketmine\network\mcpe\protocol\StopSoundPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\network\mcpe\protocol\types\PlayerAction;
use pocketmine\player\ChunkSelector;
use pocketmine\player\Player;
use pocketmine\player\UsedChunkStatus;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\scheduler\TaskHandler;
use pocketmine\utils\SingletonTrait;
use pocketmine\world\World;
use ReflectionException;
use ReflectionProperty;
use Throwable;
use function array_key_first;
use function count;
use function floor;
use function max;
use function min;
use function spl_object_id;
use const M_SQRT2;

/**
 * A single player's fake-dimension transfer state.
 *
 * The stored World and chunk coordinates are captured after the teleport
 * has completed, so chunk readiness is checked against the destination.
 */
final class TransferState{
    public ?TaskHandler $timeoutTask = null;
    public ?TaskHandler $chunkPollTask = null;
    public ?TaskHandler $returnTask = null;
    public bool $returnScheduled = false;

    public function __construct(
        public readonly Player $player,
        public readonly World $world,
        /** @var int[] Hashes of all chunks that must reach SENT before closing the screen */
        public readonly array $requiredChunkHashes,
        public readonly ?int $originalChunksPerTick
    ){}
}

final class Main extends PluginBase{
    use SingletonTrait;

    /** @var array<string, DimensionIds::*> World folder => Bedrock dimension ID */
    private array $applicableWorlds = [];

    /** @var array<int, Compressor> */
    private array $knownCompressors = [];

    /** @var array<string, TransferState> Player name => transfer state */
    private array $transferring = [];

    /** @var array<string, Player> Player name => queued player */
    private array $transferQueue = [];

    private int $maxConcurrentTransfers = 15;
    private int $configuredChunkRadius = 2;
    private int $returnDelayTicks = 1;
    private int $transferTimeoutSeconds = 30;
    private int $transferChunksPerTick = 8;
    private bool $waitForFullViewDistance = true;

    private ?ReflectionProperty $dimensionIdProperty = null;
    private ?ReflectionProperty $chunksPerTickProperty = null;

    protected function onLoad() : void{
        self::setInstance($this);
    }

    protected function onEnable() : void{
        $this->saveDefaultConfig();
        $this->loadSettings();
        $this->initializeReflectionProperties();

        $pluginManager = $this->getServer()->getPluginManager();

        $pluginManager->registerEvent(
            PlayerLoginEvent::class,
            function(PlayerLoginEvent $event) : void{
                $this->registerKnownCompressor(
                    $event->getPlayer()->getNetworkSession()->getCompressor()
                );
            },
            EventPriority::LOWEST,
            $this
        );

        $pluginManager->registerEvent(
            WorldLoadEvent::class,
            function(WorldLoadEvent $event) : void{
                $this->registerHackToWorldIfApplicable($event->getWorld());
            },
            EventPriority::LOWEST,
            $this
        );

        $pluginManager->registerEvent(
            LowMemoryEvent::class,
            function(LowMemoryEvent $event) : void{
                foreach($this->getServer()->getWorldManager()->getWorlds() as $world){
                    $this->registerHackToWorldIfApplicable($world);
                }
            },
            EventPriority::LOWEST,
            $this
        );

        // Run after teleport listeners have had an opportunity to cancel or
        // modify the destination. The actual transfer starts one tick later.
        $pluginManager->registerEvent(
            EntityTeleportEvent::class,
            function(EntityTeleportEvent $event) : void{
                $this->handleTeleport($event);
            },
            EventPriority::MONITOR,
            $this
        );

        $pluginManager->registerEvent(
            PlayerQuitEvent::class,
            function(PlayerQuitEvent $event) : void{
                $name = $event->getPlayer()->getName();
                unset($this->transferQueue[$name]);
                $this->finishTransfer($name);
            },
            EventPriority::MONITOR,
            $this
        );

        // Ensure the standard compressor's chunk caches are also registered.
        $this->registerKnownCompressor(ZlibCompressor::getInstance());

        // Drain the queue as soon as a transfer slot becomes available.
        $this->getScheduler()->scheduleRepeatingTask(
            new ClosureTask(function() : void{
                $this->processTransferQueue();
            }),
            1
        );
    }

    protected function onDisable() : void{
        foreach(array_keys($this->transferring) as $name){
            // Restore both the protocol dimension and the player's normal
            // chunk request limit before the plugin is unloaded.
            $this->finishTransfer($name, null, true);
        }

        $this->transferQueue = [];
    }

    private function initializeReflectionProperties() : void{
        try{
            $this->dimensionIdProperty = new ReflectionProperty(
                ChunkCache::class,
                "dimensionId"
            );
        }catch(ReflectionException $e){
            $this->getLogger()->critical(
                "Cannot access ChunkCache::dimensionId; fake dimension chunk-cache mapping is unavailable: " . $e->getMessage()
            );
        }

        try{
            $this->chunksPerTickProperty = new ReflectionProperty(
                Player::class,
                "chunksPerTick"
            );
        }catch(ReflectionException $e){
            $this->getLogger()->warning(
                "Per-player chunk-send boost is unavailable on this server build: " . $e->getMessage()
            );
        }
    }

    private function loadSettings() : void{
        $config = $this->getConfig();

        $this->maxConcurrentTransfers = max(
            1,
            min(100, (int) $config->get("max-concurrent-transfers", 15))
        );

        $this->configuredChunkRadius = max(
            1,
            min(5, (int) $config->get("chunk-radius", 2))
        );

        $this->returnDelayTicks = max(
            1,
            min(100, (int) $config->get("return-delay-ticks", 1))
        );

        $this->transferTimeoutSeconds = max(
            5,
            min(300, (int) $config->get("transfer-timeout-seconds", 30))
        );

        $this->transferChunksPerTick = max(
            4,
            min(16, (int) $config->get("transfer-chunks-per-tick", 8))
        );

        $this->waitForFullViewDistance = (bool) $config->get(
            "wait-for-full-view-distance",
            true
        );
    }

    /**
     * EntityTeleportEvent is called before Player::teleport() commits the
     * destination. Defer one tick and verify the destination afterwards.
     */
    private function handleTeleport(EntityTeleportEvent $event) : void{
        $player = $event->getEntity();

        if(!$player instanceof Player || $event->isCancelled()){
            return;
        }

        $fromWorld = $event->getFrom()->getWorld();
        $toWorld = $event->getTo()->getWorld();

        if($fromWorld === $toWorld || $fromWorld->getFolderName() === $toWorld->getFolderName()){
            return;
        }

        $this->getScheduler()->scheduleDelayedTask(
            new ClosureTask(function() use ($player, $toWorld) : void{
                if(!$player->isConnected() || !$player->isAlive()){
                    return;
                }

                // Ignore this event if another teleport superseded it.
                if($player->getWorld() !== $toWorld){
                    return;
                }

                $this->queueOrStartTransfer($player);
            }),
            1
        );
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

            // The player has entered another world during an existing transfer.
            $this->finishTransfer($name, $activeState, true);
        }

        if(count($this->transferring) >= $this->maxConcurrentTransfers){
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

        // Match the exact chunk selection used by Player::orderChunks().
        // By default, keep the fake screen until the entire negotiated view
        // distance has been sent, rather than checking an arbitrary 3x3/5x5
        // square that could leave most of the visible terrain still loading.
        $clientViewDistance = max(1, $player->getViewDistance());
        $allowedViewDistance = max(
            1,
            $this->getServer()->getAllowedViewDistance($clientViewDistance)
        );

        $requiredChunkHashes = [];

        if($this->waitForFullViewDistance){
            $selector = new ChunkSelector();
            foreach($selector->selectChunks($allowedViewDistance, $chunkX, $chunkZ) as $hash){
                $requiredChunkHashes[] = $hash;
            }
        }else{
            // Optional smaller readiness area for servers that prefer a
            // shorter transition over waiting for the complete view distance.
            $viewSafeRadius = (int) floor($allowedViewDistance / M_SQRT2);
            $chunkRadius = min($this->configuredChunkRadius, $viewSafeRadius);

            for($dx = -$chunkRadius; $dx <= $chunkRadius; ++$dx){
                for($dz = -$chunkRadius; $dz <= $chunkRadius; ++$dz){
                    $requiredChunkHashes[] = World::chunkHash($chunkX + $dx, $chunkZ + $dz);
                }
            }
        }

        $originalChunksPerTick = $this->applyTemporaryChunkSendBoost($player);

        $state = new TransferState(
            $player,
            $world,
            $requiredChunkHashes,
            $originalChunksPerTick
        );

        $this->transferring[$name] = $state;

        // Timeout stays active through both chunk delivery and return delay.
        $state->timeoutTask = $this->getScheduler()->scheduleDelayedTask(
            new ClosureTask(function() use ($name, $state) : void{
                if(!$this->isCurrentTransfer($name, $state)){
                    return;
                }

                $this->getLogger()->warning(
                    "Chunk-send wait timed out for {$name}; restoring the destination dimension."
                );

                $this->finishTransfer($name, $state, true);
            }),
            $this->transferTimeoutSeconds * 20
        );

        $this->showFakeNetherScreen($player);

        // The player performs normal chunk ordering after teleport. Poll the
        // public SENT status instead of pre-generating chunks unrelated to the
        // player's actual network send queue.
        $state->chunkPollTask = $this->getScheduler()->scheduleRepeatingTask(
            new ClosureTask(function() use ($name, $state) : void{
                $this->pollRequiredChunks($name, $state);
            }),
            1
        );
    }

    /**
     * Temporarily increases the number of outstanding chunk requests allowed
     * by Player::requestChunks(). Returns the original value for restoration.
     */
    private function applyTemporaryChunkSendBoost(Player $player) : ?int{
        if($this->chunksPerTickProperty === null){
            return null;
        }

        try{
            $original = (int) $this->chunksPerTickProperty->getValue($player);
            $boosted = max($original, $this->transferChunksPerTick);

            if($boosted !== $original){
                $this->chunksPerTickProperty->setValue($player, $boosted);
            }

            return $original;
        }catch(Throwable $e){
            $this->getLogger()->warning(
                "Could not apply temporary chunk-send boost to {$player->getName()}: " . $e->getMessage()
            );
            return null;
        }
    }

    /**
     * Wait until every required destination chunk is marked SENT by
     * PocketMine's player chunk-send pipeline.
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
            // A new destination superseded this transfer.
            $this->finishTransfer($name, $state, true);
            return;
        }

        $usedChunks = $player->getUsedChunks();

        foreach($state->requiredChunkHashes as $chunkHash){
            if(($usedChunks[$chunkHash] ?? null) !== UsedChunkStatus::SENT){
                return;
            }
        }

        if($state->returnScheduled){
            return;
        }

        $state->returnScheduled = true;
        $state->chunkPollTask?->cancel();
        $state->chunkPollTask = null;

        $state->returnTask = $this->getScheduler()->scheduleDelayedTask(
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
            $this->returnDelayTicks
        );
    }

    private function showFakeNetherScreen(Player $player) : void{
        $session = $player->getNetworkSession();
        $position = $player->getPosition();
        $blockPosition = BlockPosition::fromVector3($player->getLocation());

        $session->sendDataPacket(
            ChangeDimensionPacket::create(
                DimensionIds::NETHER,
                $position,
                false,
                null
            )
        );

        $session->sendDataPacket(
            PlayerActionPacket::create(
                $player->getId(),
                PlayerAction::DIMENSION_CHANGE_ACK,
                $blockPosition,
                $blockPosition,
                0
            )
        );

        $session->sendDataPacket(
            StopSoundPacket::create("portal.travel", true, true)
        );
    }

    /**
     * Restores the protocol dimension mapped to the player's current world.
     * Worlds without an explicit mapping use OVERWORLD.
     */
    private function restoreTargetDimension(Player $player) : void{
        $session = $player->getNetworkSession();
        $world = $player->getWorld();

        $dimensionId = $this->applicableWorlds[$world->getFolderName()]
            ?? DimensionIds::OVERWORLD;

        $position = $player->getPosition();
        $blockPosition = BlockPosition::fromVector3($player->getLocation());

        $session->syncGameMode($player->getGamemode());
        $session->syncAbilities($player);
        $session->syncAdventureSettings();
        $session->syncViewAreaRadius($player->getViewDistance());
        $session->syncPlayerSpawnPoint($player->getSpawn());
        $session->syncAvailableCommands();
        $session->onEnterWorld();
        $session->syncPlayerList($world->getPlayers());

        $session->sendDataPacket(
            ChangeDimensionPacket::create(
                $dimensionId,
                $position,
                false,
                null
            )
        );

        $session->sendDataPacket(
            PlayerActionPacket::create(
                $player->getId(),
                PlayerAction::DIMENSION_CHANGE_ACK,
                $blockPosition,
                $blockPosition,
                0
            )
        );

        $session->sendDataPacket(
            PlayStatusPacket::create(PlayStatusPacket::PLAYER_SPAWN)
        );

        $session->sendDataPacket(
            StopSoundPacket::create("portal.travel", true, true)
        );
    }

    private function isCurrentTransfer(string $name, TransferState $state) : bool{
        return isset($this->transferring[$name])
            && $this->transferring[$name] === $state;
    }

    /**
     * Releases the transfer and restores the original request limit.
     * Optionally sends the dimension restoration packets.
     */
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
        $state->chunkPollTask?->cancel();
        $state->returnTask?->cancel();

        $player = $state->player;

        // Restore the exact value captured before the temporary boost.
        if($state->originalChunksPerTick !== null && $this->chunksPerTickProperty !== null){
            try{
                $this->chunksPerTickProperty->setValue(
                    $player,
                    $state->originalChunksPerTick
                );
            }catch(Throwable $e){
                $this->getLogger()->warning(
                    "Could not restore chunk-send rate for {$name}: " . $e->getMessage()
                );
            }
        }

        if($restoreDimension && $player->isConnected() && $player->isAlive()){
            $this->restoreTargetDimension($player);
        }
    }

    private function processTransferQueue() : void{
        while(
            count($this->transferring) < $this->maxConcurrentTransfers
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

            // Use the player's current world and position, not stale queued data.
            $this->startTransfer($player);
        }
    }

    private function registerKnownCompressor(Compressor $compressor) : void{
        $id = spl_object_id($compressor);

        if(isset($this->knownCompressors[$id])){
            return;
        }

        $this->knownCompressors[$id] = $compressor;

        foreach($this->getServer()->getWorldManager()->getWorlds() as $world){
            $this->registerHackToWorldIfApplicable($world);
        }
    }

    private function registerHackToWorldIfApplicable(World $world) : bool{
        $folderName = $world->getFolderName();

        if(!isset($this->applicableWorlds[$folderName])){
            return false;
        }

        $this->registerHackToWorld($world, $this->applicableWorlds[$folderName]);
        return true;
    }

    /**
     * @param DimensionIds::* $dimensionId
     */
    private function registerHackToWorld(World $world, int $dimensionId) : void{
        if($this->dimensionIdProperty === null){
            return;
        }

        foreach($this->knownCompressors as $compressor){
            $chunkCache = ChunkCache::getInstance($world, $compressor);
            $this->dimensionIdProperty->setValue($chunkCache, $dimensionId);
        }
    }

    /**
     * Maps a world folder to the dimension ID used in that world's chunk cache.
     *
     * @param DimensionIds::* $dimensionId
     */
    public function applyToWorld(string $worldFolderName, int $dimensionId) : void{
        $this->applicableWorlds[$worldFolderName] = $dimensionId;

        $world = $this->getServer()->getWorldManager()->getWorldByName($worldFolderName);
        if($world !== null){
            $this->registerHackToWorldIfApplicable($world);
        }
    }

    /**
     * Removes the custom mapping and restores the OVERWORLD chunk-cache ID.
     */
    public function unapplyFromWorld(string $worldFolderName) : void{
        unset($this->applicableWorlds[$worldFolderName]);

        $world = $this->getServer()->getWorldManager()->getWorldByName($worldFolderName);
        if($world !== null){
            $this->registerHackToWorld($world, DimensionIds::OVERWORLD);
        }
    }
}
