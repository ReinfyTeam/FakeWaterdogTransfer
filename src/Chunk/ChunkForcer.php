<?php

declare(strict_types=1);

namespace ReinfyTeam\FakeWaterdogTransfer\Chunk;

use pocketmine\player\ChunkSelector;
use pocketmine\player\Player;
use pocketmine\world\World;
use ReflectionException;
use ReflectionProperty;
use Throwable;

/**
 * Forces chunks to load around a player after a dimension change.
 *
 * Uses reflection to access Player internals so chunks stream to the client
 * as fast as possible, preventing terrain glitching during fake-dimension
 * transfers.
 */
final class ChunkForcer{
    private ?ReflectionProperty $chunksPerTickProperty = null;
    private ?ReflectionProperty $nextChunkOrderRunProperty = null;
    private ?ReflectionProperty $loadQueueProperty = null;
    private ?ReflectionProperty $viewDistanceProperty = null;
    private ?ChunkSelector $chunkSelector = null;

    public function __construct(
        private readonly int $forcedChunkRadius,
        private readonly int $forcedChunksPerTick,
        private readonly bool $viewDistanceBoostEnabled,
        private readonly bool $forceWorldChunkLoading,
    ){
        $this->initializeReflectionProperties();
    }

    private function initializeReflectionProperties() : void{
        try{
            $this->chunksPerTickProperty = new ReflectionProperty(Player::class, "chunksPerTick");
        }catch(ReflectionException $e){}

        try{
            $this->nextChunkOrderRunProperty = new ReflectionProperty(Player::class, "nextChunkOrderRun");
        }catch(ReflectionException $e){}

        try{
            $this->loadQueueProperty = new ReflectionProperty(Player::class, "loadQueue");
        }catch(ReflectionException $e){}

        try{
            $this->viewDistanceProperty = new ReflectionProperty(Player::class, "viewDistance");
        }catch(ReflectionException $e){}
    }

    /**
     * Forces fast chunk loading around the player. Returns the original view
     * distance so it can be restored later, and whether it was boosted.
     *
     * @return array{int originalViewDistance, bool viewDistanceBoosted}
     */
    public function force(Player $player) : array{
        $originalViewDistance = (int) $player->getViewDistance();
        $viewDistanceBoosted = false;

        $this->boostChunksPerTick($player);
        $this->forceImmediateChunkOrder($player);

        if($this->viewDistanceProperty !== null && $this->viewDistanceBoostEnabled){
            try{
                $currentVD = (int) $this->viewDistanceProperty->getValue($player);
                if($currentVD === -1 || $currentVD < $this->forcedChunkRadius){
                    $this->viewDistanceProperty->setValue($player, $this->forcedChunkRadius);
                    $viewDistanceBoosted = true;
                }
            }catch(Throwable $e){}
        }

        if($this->forceWorldChunkLoading){
            $this->forceWorldChunkLoading($player);
        }

        $this->prepopulateLoadQueue($player);

        return [$originalViewDistance, $viewDistanceBoosted];
    }

    /**
     * Restores the original view distance if it was boosted.
     */
    public function restoreViewDistance(Player $player, int $originalViewDistance, bool $viewDistanceBoosted) : void{
        if(!$viewDistanceBoosted || $this->viewDistanceProperty === null){
            return;
        }

        try{
            $this->viewDistanceProperty->setValue($player, $originalViewDistance);
        }catch(Throwable $e){}
    }

    private function boostChunksPerTick(Player $player) : void{
        if($this->chunksPerTickProperty === null){
            return;
        }

        try{
            $original = (int) $this->chunksPerTickProperty->getValue($player);
            $newValue = max($original, $this->forcedChunksPerTick);
            if($newValue !== $original){
                $this->chunksPerTickProperty->setValue($player, $newValue);
            }
        }catch(Throwable $e){}
    }

    private function forceImmediateChunkOrder(Player $player) : void{
        if($this->nextChunkOrderRunProperty === null){
            return;
        }

        try{
            $this->nextChunkOrderRunProperty->setValue($player, 0);
        }catch(Throwable $e){}
    }

    private function forceWorldChunkLoading(Player $player) : void{
        $radius = $this->forcedChunkRadius;
        $position = $player->getPosition();
        $centerX = $position->getFloorX() >> 4;
        $centerZ = $position->getFloorZ() >> 4;
        $world = $player->getWorld();

        try{
            // Load chunks in a square around the player, skipping already-loaded ones.
            for($dx = -$radius; $dx <= $radius; ++$dx){
                $x = $centerX + $dx;
                for($dz = -$radius; $dz <= $radius; ++$dz){
                    $z = $centerZ + $dz;
                    if(!$world->isChunkLoaded($x, $z)){
                        $world->loadChunk($x, $z);
                    }
                }
            }
        }catch(Throwable $e){
            $player->getServer()->getLogger()->warning(
                "Failed to force-load chunks for {$player->getName()}: " . $e->getMessage()
            );
        }
    }

    private function prepopulateLoadQueue(Player $player) : void{
        if($this->loadQueueProperty === null){
            return;
        }

        try{
            $loadQueue = (array) $this->loadQueueProperty->getValue($player);
            $selector = $this->getChunkSelector();
            $centerX = (int) ($player->getPosition()->getFloorX() >> 4);
            $centerZ = (int) ($player->getPosition()->getFloorZ() >> 4);

            foreach($selector->selectChunks($this->forcedChunkRadius, $centerX, $centerZ) as $radius => $hash){
                if(!isset($loadQueue[$hash])){
                    $loadQueue[$hash] = $radius;
                }
            }

            $this->loadQueueProperty->setValue($player, $loadQueue);
        }catch(Throwable $e){}
    }

    private function getChunkSelector() : ChunkSelector{
        if($this->chunkSelector === null){
            $this->chunkSelector = new ChunkSelector();
        }
        return $this->chunkSelector;
    }
}
