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
 */

declare(strict_types=1);

namespace ReinfyTeam\FakeWaterdogTransfer\Transfer;

use pocketmine\player\Player;
use pocketmine\scheduler\Task;
use pocketmine\scheduler\TaskHandler;
use pocketmine\world\World;

/**
 * A single player's fake-dimension transfer state.
 *
 * The stored World and chunk coordinates are captured after the teleport
 * has completed, so chunk readiness is checked against the destination.
 *
 * All timing is tick-based (via TransferManager::tick()) instead of
 * per-player ClosureTasks, so the plugin scales to 100+ concurrent players.
 */
final class TransferState {
	// --- Transfer lifecycle tasks ---
	/** @var TaskHandler<Task>|null */
	public ?TaskHandler $timeoutTask = null;

	/** @var TaskHandler<Task>|null */
	public ?TaskHandler $returnTask = null;

	// --- Transfer flags ---
	public bool $returnScheduled = false;

	// --- View distance / chunk rate restoration ---
	public int $originalViewDistance = -1;
	public bool $viewDistanceBoosted = false;

	// --- Chunk polling ---
	public int $lastSentCount = 0;
	public int $ticksWithoutProgress = 0;
	public int $chunkNudgeTick = 0;

	// --- Tick-based timing (replaces per-player ClosureTasks) ---

	/** Ticks elapsed since transfer started. */
	public int $ticksSinceStart = 0;

	/** Whether the fake Nether screen has been shown. */
	public bool $netherScreenShown = false;

	/** Return phase: 0=none, 1=waiting before Overworld screen, 2=waiting before real change. */
	public int $returnPhase = 0;

	/** Ticks elapsed in the current return phase. */
	public int $returnPhaseTicks = 0;

	/** Ticks elapsed since transfer started (for timeout tracking). */
	public int $timeoutTicks = 0;

	/** Timeout threshold in ticks (0 = disabled). */
	public int $timeoutThreshold = 0;

	public function __construct(
		public readonly Player $player,
		public readonly World $world,
		/** @var int[] Hashes of all chunks that must reach SENT before closing the screen */
		public readonly array $requiredChunkHashes,
		public readonly ?int $originalChunksPerTick
	) {
	}
}
