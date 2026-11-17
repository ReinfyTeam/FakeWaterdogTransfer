<?php

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
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\SingletonTrait;
use pocketmine\world\World;
use ReinfyTeam\FakeWaterdogTransfer\Chunk\ChunkForcer;
use ReinfyTeam\FakeWaterdogTransfer\Config\TransferConfig;
use ReinfyTeam\FakeWaterdogTransfer\Transfer\TransferManager;
use ReflectionException;
use ReflectionProperty;
use spl_object_id;

final class Main extends PluginBase{
    use SingletonTrait;

    /** @var array<int, Compressor> */
    private array $knownCompressors = [];

    private TransferManager $transferManager;
    private TransferConfig $config;
    private ChunkForcer $chunkForcer;

    private ?ReflectionProperty $dimensionIdProperty = null;

    protected function onLoad() : void{
        self::setInstance($this);
    }

    protected function onEnable() : void{
        $this->saveDefaultConfig();

        $this->config = new TransferConfig($this);
        $this->chunkForcer = new ChunkForcer(
            $this->config->getForcedChunkRadius(),
            $this->config->getForcedChunksPerTick(),
            $this->config->getTemporaryViewDistanceBoost(),
            $this->config->getForceWorldChunkLoading()
        );
        $this->transferManager = new TransferManager(
            $this->config,
            $this->chunkForcer,
            fn() => $this->getScheduler()
        );
        $this->transferManager->onEnable();

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

        $pluginManager->registerEvent(
            EntityTeleportEvent::class,
            function(EntityTeleportEvent $event) : void{
                $this->transferManager->handleTeleport($event);
            },
            EventPriority::MONITOR,
            $this
        );

        $pluginManager->registerEvent(
            PlayerQuitEvent::class,
            function(PlayerQuitEvent $event) : void{
                $this->transferManager->handlePlayerQuit($event);
            },
            EventPriority::MONITOR,
            $this
        );

        $this->registerKnownCompressor(ZlibCompressor::getInstance());
    }

    protected function onDisable() : void{
        $this->transferManager->onDisable();
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
        $this->transferManager->applyToWorld($worldFolderName, $dimensionId);

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
        $this->transferManager->unapplyFromWorld($worldFolderName);

        $world = $this->getServer()->getWorldManager()->getWorldByName($worldFolderName);
        if($world !== null){
            $this->registerHackToWorld($world, DimensionIds::OVERWORLD);
        }
    }

    /** @var array<string, int> World folder => dimension ID */
    private array $applicableWorlds = [];
}
