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
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\scheduler\TaskHandler;
use pocketmine\utils\SingletonTrait;
use pocketmine\world\World;
use ReflectionProperty;
use function count;
use function floor;
use function max;
use function min;
use function spl_object_id;
use const M_SQRT2;

/**
 * State for a player whose destination chunks are still being sent.
 */
final class TransferState{

    public ?TaskHandler $timeoutTask = null;
    public ?TaskHandler $chunkPollTask = null;
    public ?TaskHandler $returnTask = null;
    public bool $returnScheduled = false;

    public function __construct(
        public readonly Player $player,
        public readonly World $world,
        public readonly int $centerChunkX,
        public readonly int $centerChunkZ,
        public readonly int $chunkRadius
    ){}
}

final class Main extends PluginBase{
    use SingletonTrait;

    /** @var array<string, DimensionIds::*> */
    private array $applicableWorlds = [];

    /** @var array<int, Compressor> */
    private array $knownCompressors = [];

    /** @var array<string, TransferState> Player name => active transfer */
    private array $transferring = [];

    /** @var array<string, Player> Player name => player waiting for a transfer slot */
    private array $transferQueue = [];

    private int $maxConcurrentTransfers = 15;
    private int $configuredChunkRadius = 2;
    private int $returnDelayTicks = 1;
    private int $transferTimeoutSeconds = 30;

    private ?ReflectionProperty $dimensionIdProperty = null;

    protected function onLoad() : void{
        self::setInstance($this);
    }

    protected function onEnable() : void{
        $this->saveDefaultConfig();
        $this->loadSettings();

        // Cache the reflection handle instead of constructing it
        // for every world/compressor pair.
        $this->dimensionIdProperty = new ReflectionProperty(
            ChunkCache::class,
            "dimensionId"
        );

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

        // MONITOR avoids starting a fake screen for cancelled teleports.
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

        $this->registerKnownCompressor(ZlibCompressor::getInstance());

        // Process the queue frequently so a free transfer slot is not left idle.
        $this->getScheduler()->scheduleRepeatingTask(
            new ClosureTask(function() : void{
                $this->processTransferQueue();
            }),
            1
        );
    }

    protected function onDisable() : void{
        foreach(array_keys($this->transferring) as $name){
            $this->finishTransfer($name, null, true);
        }

        $this->transferQueue = [];
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
    }

    /**
     * EntityTeleportEvent fires before Player::teleport() commits
     * the destination position.
     *
     * Starting one tick later ensures getWorld() and getPosition()
     * refer to the destination.
     */
    private function handleTeleport(EntityTeleportEvent $event) : void{
        $player = $event->getEntity();

        if(!$player instanceof Player || $event->isCancelled()){
            return;
        }

        $fromWorld = $event->getFrom()->getWorld();
        $destinationWorld = $event->getTo()->getWorld();

        if($fromWorld->getFolderName() === $destinationWorld->getFolderName()){
            return;
        }

        $this->getScheduler()->scheduleDelayedTask(
            new ClosureTask(function() use ($player, $destinationWorld) : void{
                if(!$player->isConnected() || !$player->isAlive()){
                    return;
                }

                // A later teleport may have superseded this event.
                if($player->getWorld() !== $destinationWorld){
                    return;
                }

                $this->queueOrStartTransfer($player);
            }),
            1
        );
    }

    private function queueOrStartTransfer(Player $player) : void{
        $name = $player->getName();

        // Ignore duplicate callbacks for the same destination.
        // Discard stale state if the player has moved to another world.
        if(isset($this->transferring[$name])){
            if($this->transferring[$name]->world === $player->getWorld()){
                return;
            }

            $this->finishTransfer($name);
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

        $chunkX = $player->getPosition()->getFloorX() >> 4;
        $chunkZ = $player->getPosition()->getFloorZ() >> 4;

        /*
         * ChunkSelector uses a circular view-distance boundary.
         *
         * Keep the square readiness area inside that boundary so
         * the plugin never waits for chunks that the negotiated
         * view distance will not request.
         */
        $viewDistance = max(0, $player->getViewDistance());

        $viewSafeRadius = (int) floor($viewDistance / M_SQRT2);

        $chunkRadius = min(
            $this->configuredChunkRadius,
            $viewSafeRadius
        );

        $state = new TransferState(
            $player,
            $world,
            $chunkX,
            $chunkZ,
            $chunkRadius
        );

        $this->transferring[$name] = $state;

        // Timeout remains active until the entire transfer is finished,
        // including the return-delay period.
        $state->timeoutTask = $this->getScheduler()->scheduleDelayedTask(
            new ClosureTask(function() use ($name, $state) : void{
                if($this->isCurrentTransfer($name, $state)){
                    $this->getLogger()->warning(
                        "Chunk-send wait timed out for {$name}; restoring the destination dimension."
                    );

                    $this->finishTransfer($name, $state, true);
                }
            }),
            $this->transferTimeoutSeconds * 20
        );

        // Show the fake Nether loading screen.
        $this->showFakeNetherScreen($player);

        // Poll the actual player chunk-send status.
        $state->chunkPollTask = $this->getScheduler()->scheduleRepeatingTask(
            new ClosureTask(function() use ($name, $state) : void{
                $this->pollRequiredChunks($name, $state);
            }),
            1
        );
    }

    /**
     * Wait until every chunk in the configured destination area
     * has reached the SENT state for this player.
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
            // The player moved to another destination while the fake screen was active.
            $this->finishTransfer($name, $state, true);
            return;
        }

        for($dx = -$state->chunkRadius; $dx <= $state->chunkRadius; ++$dx){
            for($dz = -$state->chunkRadius; $dz <= $state->chunkRadius; ++$dz){
                if(!$player->hasReceivedChunk(
                    $state->centerChunkX + $dx,
                    $state->centerChunkZ + $dz
                )){
                    return;
                }
            }
        }

        if($state->returnScheduled){
            return;
        }

        $state->returnScheduled = true;

        $state->chunkPollTask?->cancel();
        $state->chunkPollTask = null;

        // All required chunks have been sent. Keep the screen for the
        // configured extra delay, then restore the destination dimension.
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
     * Restores the protocol dimension corresponding to the player's current world.
     *
     * The timeout path uses this same method, so it does not blindly
     * force OVERWORLD.
     */
    private function restoreTargetDimension(Player $player) : void{
        $session = $player->getNetworkSession();
        $world = $player->getWorld();

        $dimensionId = $this->applicableWorlds[$world->getFolderName()]
            ?? DimensionIds::OVERWORLD;

        $position = $player->getPosition();
        $blockPosition = BlockPosition::fromVector3($player->getLocation());

        // Synchronize the player's world state before returning.
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
     * Releases the transfer state and optionally restores the dimension.
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

            // Read the current world and position when dequeued,
            // not the position from the original teleport event.
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

        $this->registerHackToWorld(
            $world,
            $this->applicableWorlds[$folderName]
        );

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

            $this->dimensionIdProperty->setValue(
                $chunkCache,
                $dimensionId
            );
        }
    }

    /**
     * Maps a world folder to the dimension ID used when its chunks are sent.
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
     * Restores the given world's chunk-cache dimension ID to OVERWORLD.
     */
    public function unapplyFromWorld(string $worldFolderName) : void{
        unset($this->applicableWorlds[$worldFolderName]);

        $world = $this->getServer()->getWorldManager()->getWorldByName($worldFolderName);

        if($world !== null){
            $this->registerHackToWorld($world, DimensionIds::OVERWORLD);
        }
    }
}
