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

namespace ReinfyTeam\FakeWaterdogTransfer\Transfer;

use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\player\Player;
use pocketmine\player\UsedChunkStatus;
use pocketmine\scheduler\ClosureTask;
use pocketmine\scheduler\TaskHandler;
use pocketmine\scheduler\TaskScheduler;
use pocketmine\world\World;
use ReflectionProperty;
use ReinfyTeam\FakeWaterdogTransfer\Chunk\ChunkForcer;
use ReinfyTeam\FakeWaterdogTransfer\Config\TransferConfig;
use function array_key_first;
use function array_keys;
use function count;
use function floor;
use function is_int;
use function max;
use function min;
use const M_SQRT2;

/**
 * Coordinates all fake-dimension transfers with a single server tick loop.
 *
 * Having one repeating task instead of one per player keeps overhead near-zero
 * even with 100+ concurrent players transferring.
 *
 * All per-player timing is handled inside tick() via TransferState tick
 * counters instead of per-player ClosureTasks, so the plugin scales to
 * 100+ concurrent players without scheduling overhead.
 */
final class TransferManager {
	/** @var array<string, TransferState> Player name => active transfer state */
	private array $transferring = [];

	/** @var array<string, Player> Player name => queued player */
	private array $transferQueue = [];

	/** @var array<string, int> World folder => Bedrock dimension ID */
	private array $applicableWorlds = [];

	/** @var TaskHandler<ClosureTask>|null */
	private ?TaskHandler $tickTask = null;

	/** @phpstan-var \Closure(): TaskScheduler */
	private readonly \Closure $schedulerGetter;

	public function __construct(
		private readonly TransferConfig $config,
		private readonly ChunkForcer $chunkForcer,
		\Closure $schedulerGetter,
	) {
		$this->schedulerGetter = $schedulerGetter;
	}

	public function onEnable() : void {
		$this->tickTask = $this->getScheduler()->scheduleRepeatingTask(
			new ClosureTask(fn() => $this->tick()),
			1
		);
	}

	public function onDisable() : void {
		foreach (array_keys($this->transferring) as $name) {
			$this->finishTransfer($name, null, true);
		}
		$this->transferQueue = [];
		$this->tickTask?->cancel();
	}

	/**
	 * Handles EntityTeleportEvent (MONITOR priority). Deferred one tick so
	 * other listeners can cancel/modify the destination first.
	 */
	public function handleTeleport(EntityTeleportEvent $event) : void {
		$player = $event->getEntity();

		if (!$player instanceof Player || $event->isCancelled()) {
			return;
		}

		$fromWorld = $event->getFrom()->getWorld();
		$toWorld = $event->getTo()->getWorld();

		if ($fromWorld === $toWorld || $fromWorld->getFolderName() === $toWorld->getFolderName()) {
			return;
		}

		$this->getScheduler()->scheduleDelayedTask(
			new ClosureTask(function() use ($player, $toWorld) : void {
				if (!$player->isConnected() || !$player->isAlive()) {
					return;
				}
				if ($player->getWorld() !== $toWorld) {
					return;
				}
				$this->queueOrStartTransfer($player);
			}),
			1
		);
	}

	public function handlePlayerQuit(PlayerQuitEvent $event) : void {
		$name = $event->getPlayer()->getName();
		unset($this->transferQueue[$name]);
		$this->finishTransfer($name);
	}

	/**
	 * Main tick: processes the queue and polls chunk readiness for all active transfers.
	 *
	 * Runs once per server tick. All per-player timing (Nether screen delay,
	 * timeout) is handled here via TransferState tick counters instead of
	 * per-player ClosureTasks.
	 */
	public function tick() : void {
		$this->processTransferQueue();

		$names = array_keys($this->transferring);
		foreach ($names as $name) {
			if (!isset($this->transferring[$name])) {
				continue;
			}

			$state = $this->transferring[$name];
			$state->ticksSinceStart++;

			$this->handleNetherScreenTiming($name, $state);
			$this->handleTimeout($name, $state);

			// If the transfer was finished by the timeout handler, skip.
			if (!isset($this->transferring[$name])) {
				continue;
			}

			$this->pollRequiredChunks($name, $state);
		}
	}

	/**
	 * Shows the fake Nether transition screen after 5 ticks, giving chunks
	 * time to start loading before the client sees the screen.
	 */
	private function handleNetherScreenTiming(string $name, TransferState $state) : void {
		if ($state->netherScreenShown || $state->ticksSinceStart < 5) {
			return;
		}

		TransferScreen::showFakeNetherScreen($state->player);
		$state->netherScreenShown = true;
	}

	/**
	 * Checks whether the transfer timeout has been exceeded.
	 *
	 * If timed out, kicks the player or finishes the transfer depending on
	 * configuration, then removes the state from the transferring list.
	 */
	private function handleTimeout(string $name, TransferState $state) : void {
		if ($state->timeoutThreshold <= 0) {
			return;
		}

		$state->timeoutTicks++;

		if ($state->timeoutTicks < $state->timeoutThreshold) {
			return;
		}

		$state->timeoutTicks = 0;
		$state->timeoutThreshold = 0;

		$player = $state->player;
		$player->getServer()->getLogger()->warning(
			"Chunk-send wait timed out for {$name}; restoring the destination dimension."
		);

		if ($this->config->getKickStuckPlayers()) {
			$this->kickStuckPlayer($player);
		} else {
			$this->finishTransfer($name, $state, true);
		}
	}

	private function queueOrStartTransfer(Player $player) : void {
		$name = $player->getName();

		if (!$player->isConnected() || !$player->isAlive()) {
			return;
		}

		if (isset($this->transferring[$name])) {
			$activeState = $this->transferring[$name];
			if ($activeState->world === $player->getWorld()) {
				return;
			}
			$this->finishTransfer($name, $activeState, true);
		}

		if (count($this->transferring) >= $this->config->getMaxConcurrentTransfers()) {
			$this->transferQueue[$name] = $player;
			return;
		}

		unset($this->transferQueue[$name]);
		$this->startTransfer($player);
	}

	private function startTransfer(Player $player) : void {
		if (!$player->isConnected() || !$player->isAlive()) {
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

		$requiredChunkHashes = $this->calculateRequiredChunkHashes($allowedViewDistance, $chunkX, $chunkZ);

		$originalChunksPerTick = $this->applyTemporaryChunkSendBoost($player);

		// Force fast chunk loading before creating state so chunks start moving immediately.
		$forceResult = $this->chunkForcer->force($player);
		$originalViewDistance = $forceResult["originalViewDistance"];
		$viewDistanceBoosted = $forceResult["viewDistanceBoosted"];

		$state = new TransferState(
			$player,
			$world,
			$requiredChunkHashes,
			$originalChunksPerTick
		);
		$state->originalViewDistance = $originalViewDistance;
		$state->viewDistanceBoosted = $viewDistanceBoosted;

		$this->transferring[$name] = $state;

		// Timeout threshold (ticks). Checked each tick in tick() instead of
		// scheduling a per-player ClosureTask — scales to 100+ players.
		$state->timeoutThreshold = $this->config->getTransferTimeoutSeconds() * 20;

		// The fake Nether screen is shown in tick() after 5 ticks.
	}

	/**
	 * Calculates the chunk hashes that must reach SENT before the transfer
	 * screen can close.
	 *
	 * @return int[] List of chunk hashes
	 */
	private function calculateRequiredChunkHashes(int $allowedViewDistance, int $chunkX, int $chunkZ) : array {
		$requiredChunkHashes = [];

		if ($this->config->getWaitForFullViewDistance()) {
			$selector = new \pocketmine\player\ChunkSelector();
			foreach ($selector->selectChunks($allowedViewDistance, $chunkX, $chunkZ) as $hash) {
				$requiredChunkHashes[] = $hash;
			}
		} else {
			$viewSafeRadius = (int) floor($allowedViewDistance / M_SQRT2);
			$chunkRadius = min($this->config->getConfiguredChunkRadius(), $viewSafeRadius);

			for ($dx = -$chunkRadius; $dx <= $chunkRadius; ++$dx) {
				for ($dz = -$chunkRadius; $dz <= $chunkRadius; ++$dz) {
					$requiredChunkHashes[] = World::chunkHash($chunkX + $dx, $chunkZ + $dz);
				}
			}
		}

		return $requiredChunkHashes;
	}

	/**
	 * Temporarily increases chunksPerTick for the duration of the transfer.
	 */
	private function applyTemporaryChunkSendBoost(Player $player) : ?int {
		$prop = $this->getReflectionProperty(Player::class, "chunksPerTick");
		if ($prop === null) {
			return null;
		}

		try {
			$original = $prop->getValue($player);
			if (!is_int($original)) {
				$original = 0;
			}
			$boosted = max($original, $this->config->getTransferChunksPerTick());
			if ($boosted !== $original) {
				$prop->setValue($player, $boosted);
			}
			return $original;
		} catch(\Throwable $e) {
			$player->getServer()->getLogger()->warning(
				"Could not apply temporary chunk-send boost to {$player->getName()}: " . $e->getMessage()
			);
			return null;
		}
	}

	/**
	 * Polls a single transfer: checks if all required chunks are SENT.
	 *
	 * When all chunks are sent, finishes the transfer with a real dimension
	 * change so the client can spawn.
	 */
	private function pollRequiredChunks(string $name, TransferState $state) : void {
		if (!$this->isCurrentTransfer($name, $state)) {
			return;
		}

		$player = $state->player;

		if (!$player->isConnected() || !$player->isAlive()) {
			$this->finishTransfer($name, $state);
			return;
		}

		if ($player->getWorld() !== $state->world) {
			$this->finishTransfer($name, $state, true);
			return;
		}

		$usedChunks = $player->getUsedChunks();

		$this->trackChunkProgress($state, $usedChunks);

		if ($this->hasStalledTooLong($player, $name, $state)) {
			return;
		}

		if (!$this->areAllChunksSent($state, $usedChunks)) {
			return;
		}

		// All chunks ready — finish with real dimension change.
		$this->finishTransfer($name, $state, true);
	}

	/**
	 * Tracks how many required chunks have reached SENT status and updates
	 * progress counters for stuck detection.
	 *
	 * @param array<int, UsedChunkStatus> $usedChunks Chunk hash => UsedChunkStatus map
	 */
	private function trackChunkProgress(TransferState $state, array $usedChunks) : void {
		$sentCount = 0;
		foreach ($state->requiredChunkHashes as $chunkHash) {
			if (($usedChunks[$chunkHash] ?? null) === UsedChunkStatus::SENT) {
				$sentCount++;
			}
		}

		if ($sentCount > $state->lastSentCount) {
			$state->lastSentCount = $sentCount;
			$state->ticksWithoutProgress = 0;
		} elseif ($sentCount < count($state->requiredChunkHashes)) {
			$state->ticksWithoutProgress++;
		}
	}

	/**
	 * Checks whether the transfer has stalled too long without progress.
	 * If so, kicks the player and returns true (caller should skip).
	 */
	private function hasStalledTooLong(Player $player, string $name, TransferState $state) : bool {
		$sentCount = 0;
		foreach ($state->requiredChunkHashes as $chunkHash) {
			if (($player->getUsedChunks()[$chunkHash] ?? null) === UsedChunkStatus::SENT) {
				$sentCount++;
			}
		}

		if ($sentCount === count($state->requiredChunkHashes)) {
			return false;
		}

		$maxIdleTicks = $this->config->getKickStuckAfterSeconds() * 20;
		if ($state->ticksWithoutProgress >= $maxIdleTicks) {
			$player->getServer()->getLogger()->warning(
				"Kicking {$name} — no chunk transfer progress for " . $this->config->getKickStuckAfterSeconds() . " seconds."
			);
			$this->kickStuckPlayer($player);
			return true;
		}

		return false;
	}

	/**
	 * Returns true if all required chunks have reached SENT status.
	 *
	 * @param array<int, UsedChunkStatus> $usedChunks Chunk hash => UsedChunkStatus map
	 */
	private function areAllChunksSent(TransferState $state, array $usedChunks) : bool {
		foreach ($state->requiredChunkHashes as $chunkHash) {
			if (($usedChunks[$chunkHash] ?? null) !== UsedChunkStatus::SENT) {
				return false;
			}
		}
		return true;
	}

	private function finishTransfer(
		string $name,
		?TransferState $expected = null,
		bool $restoreDimension = false
	) : void {
		$state = $this->transferring[$name] ?? null;

		if ($state === null || ($expected !== null && $state !== $expected)) {
			return;
		}

		unset($this->transferring[$name]);

		$player = $state->player;

		// Restore chunksPerTick to the value before the temporary boost.
		$originalChunksPerTickProp = $this->getReflectionProperty(Player::class, "chunksPerTick");
		if ($state->originalChunksPerTick !== null && $originalChunksPerTickProp !== null) {
			try {
				$originalChunksPerTickProp->setValue($player, $state->originalChunksPerTick);
			} catch(\Throwable $e) {
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

		if ($restoreDimension && $player->isConnected() && $player->isAlive()) {
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
	private function kickStuckPlayer(Player $player) : void {
		$name = $player->getName();

		// Clean up transfer state first (don't restore dimension, just release)
		$this->finishTransfer($name, null, false);

		if (!$player->isConnected()) {
			return;
		}

		$player->kick($this->config->getKickMessage());
	}

	private function processTransferQueue() : void {
		while (
			count($this->transferring) < $this->config->getMaxConcurrentTransfers()
			&& count($this->transferQueue) > 0
		) {
			$name = array_key_first($this->transferQueue);
			$player = $this->transferQueue[$name];
			unset($this->transferQueue[$name]);

			if (!$player->isConnected() || !$player->isAlive()) {
				continue;
			}

			if (isset($this->transferring[$name])) {
				continue;
			}

			$this->startTransfer($player);
		}
	}

	private function isCurrentTransfer(string $name, TransferState $state) : bool {
		return isset($this->transferring[$name])
			&& $this->transferring[$name] === $state;
	}

	/**
	 * Maps a world folder to the dimension ID used in that world's chunk cache.
	 *
	 * @param int $dimensionId DimensionIds::* constant
	 */
	public function applyToWorld(string $worldFolderName, int $dimensionId) : void {
		$this->applicableWorlds[$worldFolderName] = $dimensionId;
	}

	/**
	 * Removes the custom mapping and restores the OVERWORLD chunk-cache ID.
	 */
	public function unapplyFromWorld(string $worldFolderName) : void {
		unset($this->applicableWorlds[$worldFolderName]);
	}

	/**
	 * Returns the server's scheduler.
	 */
	private function getScheduler() : TaskScheduler {
		/** @var TaskScheduler $scheduler */
		$scheduler = ($this->schedulerGetter)();
		return $scheduler;
	}

	// --- Reflection helper -------------------------------------------------

	/**
	 * Gets a ReflectionProperty for the given class and property name.
	 * Results are cached for performance.
	 *
	 * @param class-string $class The class name
	 * @return \ReflectionProperty|null The reflection property, or null if not found
	 */
	private function getReflectionProperty(string $class, string $property) : ?\ReflectionProperty {
		/** @var array<string, \ReflectionProperty|null> */
		static $cache = [];

		$key = $class . "::" . $property;
		if (isset($cache[$key])) {
			return $cache[$key];
		}

		try {
			$ref = new \ReflectionProperty($class, $property);
			$cache[$key] = $ref;
			return $ref;
		} catch(\ReflectionException $e) {
			$cache[$key] = null;
			return null;
		}
	}
}
