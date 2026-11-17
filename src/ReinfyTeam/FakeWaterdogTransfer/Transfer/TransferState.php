<?php

declare(strict_types=1);

namespace ReinfyTeam\FakeWaterdogTransfer\Transfer;

use pocketmine\player\Player;
use pocketmine\scheduler\ClosureTask;
use pocketmine\scheduler\TaskHandler;
use pocketmine\world\World;

/**
 * A single player's fake-dimension transfer state.
 *
 * The stored World and chunk coordinates are captured after the teleport
 * has completed, so chunk readiness is checked against the destination.
 */
final class TransferState{
    /** @var TaskHandler<ClosureTask>|null */
    public ?TaskHandler $timeoutTask = null;
    /** @var TaskHandler<ClosureTask>|null */
    public ?TaskHandler $returnTask = null;
    public bool $returnScheduled = false;
    public int $originalViewDistance = -1;
    public bool $viewDistanceBoosted = false;
    public int $lastSentCount = 0;
    public int $ticksWithoutProgress = 0;

    public function __construct(
        public readonly Player $player,
        public readonly World $world,
        /** @var int[] Hashes of all chunks that must reach SENT before closing the screen */
        public readonly array $requiredChunkHashes,
        public readonly ?int $originalChunksPerTick
    ){}
}
