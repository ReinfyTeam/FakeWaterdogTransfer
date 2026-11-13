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

use pocketmine\plugin\PluginBase;
use pocketmine\utils\SingletonTrait;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\event\EventPriority;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\event\server\LowMemoryEvent;
use pocketmine\event\world\WorldLoadEvent;
use pocketmine\network\mcpe\cache\ChunkCache;
use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\network\mcpe\protocol\ChangeDimensionPacket;
use pocketmine\network\mcpe\protocol\PlayerActionPacket;
use pocketmine\network\mcpe\protocol\PlayStatusPacket;
use pocketmine\network\mcpe\protocol\StopSoundPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\network\mcpe\protocol\types\PlayerAction;
use pocketmine\player\Player;
use pocketmine\scheduler\ClosureTask;
use pocketmine\Server;
use pocketmine\world\World;
use ReflectionProperty;
use function spl_object_id;
use function time;

/**
 * Tracks an in-progress transfer for a player.
 */
final class TransferState {
	public int $startTime;
	public int $chunkRadius;
	public int $totalChunks;
	public int $chunksLoaded;
	public bool $completed;
	public ?ClosureTask $timeoutTask = null;

	public function __construct(int $startTime, int $chunkRadius, int $totalChunks) {
		$this->startTime = $startTime;
		$this->chunkRadius = $chunkRadius;
		$this->totalChunks = $totalChunks;
		$this->chunksLoaded = 0;
		$this->completed = false;
	}
}

final class Main extends PluginBase {
	use SingletonTrait;

	/** @var array<string, DimensionIds::*> */
	private array $applicable_worlds = [];

	/** @var Compressor[] */
	private array $known_compressors = [];

	/** @var array<string, TransferState> */
	private array $transferring = [];

	/** @var array<string, mixed> */
	private array $config = [];

	/** @var int Maximum concurrent transfers to prevent chunk loading storms */
	private int $maxConcurrentTransfers = 10;

	/** @var int Currently active transfer count */
	private int $activeTransferCount = 0;

	/** @var array<string, Player> Queue of player names waiting to transfer */
	private array $transferQueue = [];

    protected function onLoad() : void {
		self::setInstance($this);
	}
	
	protected function onEnable() : void {
		$this->saveDefaultConfig();
		$this->reloadConfig();
		$this->loadConfig();
		Server::getInstance()->getPluginManager()->registerEvent(PlayerLoginEvent::class, function(PlayerLoginEvent $event) : void {
			$this->registerKnownCompressor($event->getPlayer()->getNetworkSession()->getCompressor());
		}, EventPriority::LOWEST, $this);
		Server::getInstance()->getPluginManager()->registerEvent(WorldLoadEvent::class, function(WorldLoadEvent $event) : void {
			$this->registerHackToWorldIfApplicable($event->getWorld());
		}, EventPriority::LOWEST, $this);
		Server::getInstance()->getPluginManager()->registerEvent(LowMemoryEvent::class, function(LowMemoryEvent $event) : void {
			foreach (Server::getInstance()->getWorldManager()->getWorlds() as $world) {
				$this->registerHackToWorldIfApplicable($world);
			}
		}, EventPriority::LOWEST, $this);

		// register already-registered values
		$this->registerKnownCompressor(ZlibCompressor::getInstance());

		Server::getInstance()->getPluginManager()->registerEvent(EntityTeleportEvent::class, function(EntityTeleportEvent $event) : void {
			$entity = $event->getEntity();
			if (!$entity instanceof Player) {
				return;
			}

			$name = $entity->getName();

			// Already transferring - ignore duplicate teleport events
			if (isset($this->transferring[$name])) {
				return;
			}

			// Rate limiting: if at max concurrent transfers, queue this player
			if ($this->activeTransferCount >= $this->maxConcurrentTransfers) {
				$this->transferQueue[$name] = $entity;
				return;
			}

			$from = $event->getFrom()->getWorld();
			$to = $event->getTo()->getWorld();

			// only run effect if teleporting between worlds
			if ($from->getFolderName() === $to->getFolderName()) {
				return;
			}

			$this->startTransfer($entity);
		}, EventPriority::LOWEST, $this);

		// Periodic cleanup of stuck transfers and processing of queued transfers
		Server::getInstance()->getScheduler()->scheduleRepeatingTask(new ClosureTask(function () : void {
			$this->processTransferQueue();
			$this->cleanupStuckTransfers();
		}), 20); // every second
	}

	/**
	 * Load configuration values from config.yml
	 */
	private function loadConfig() : void {
		$this->maxConcurrentTransfers = (int) ($this->config["max-concurrent-transfers"] ?? 10);
		if ($this->maxConcurrentTransfers < 1) {
			$this->maxConcurrentTransfers = 1;
		}
	}

	private function reloadConfig() : void {
		$this->config = $this->getConfig()->getAll();
	}

	/**
	 * Start a transfer sequence for a player.
	 */
	private function startTransfer(Player $player) : void {
		$name = $player->getName();
		$now = time();

		$chunkRadius = (int) ($this->config["chunk-radius"] ?? 2);
		if ($chunkRadius < 1) {
			$chunkRadius = 1;
		}
		if ($chunkRadius > 5) {
			$chunkRadius = 5;
		}

		$diameter = ($chunkRadius * 2) + 1;
		$totalChunks = $diameter * $diameter;

		$this->transferring[$name] = new TransferState($now, $chunkRadius, $totalChunks);
		$this->activeTransferCount++;

		$this->sendTeleportScreen($player, $this->transferring[$name]);
	}

	/**
	 * Process queued transfers when slots become available.
	 */
	private function processTransferQueue() : void {
		if ($this->activeTransferCount >= $this->maxConcurrentTransfers) {
			return;
		}

		while ($this->activeTransferCount < $this->maxConcurrentTransfers && count($this->transferQueue) > 0) {
			$name = array_key_first($this->transferQueue);
			$player = $this->transferQueue[$name];
			unset($this->transferQueue[$name]);

			// Player may have disconnected while queued
			if (!$player instanceof Player || !$player->isConnected() || !$player->isAlive()) {
				continue;
			}

			// Already transferred or state cleaned up
			if (isset($this->transferring[$name])) {
				continue;
			}

			$this->startTransfer($player);
		}
	}

	/**
	 * Clean up transfers that have been stuck too long (timeout protection).
	 */
	private function cleanupStuckTransfers() : void {
		$timeout = (int) ($this->config["transfer-timeout-seconds"] ?? 30);
		$now = time();

		foreach ($this->transferring as $name => $state) {
			if ($state->completed) {
				continue;
			}

			if (($now - $state->startTime) > $timeout) {
				$player = Server::getInstance()->getPlayerExact($name);
				if ($player !== null && $player->isConnected() && $player->isAlive()) {
					// Force return to overworld to prevent stuck-at-nether glitch
					$session = $player->getNetworkSession();
					$session->sendDataPacket(ChangeDimensionPacket::create(
						DimensionIds::OVERWORLD,
						$player->getPosition(),
						false,
						null
					));
					$session->sendDataPacket(PlayerActionPacket::create(
						$player->getId(),
						PlayerAction::DIMENSION_CHANGE_ACK,
						BlockPosition::fromVector3($player->getLocation()),
						BlockPosition::fromVector3($player->getLocation()),
						0
					));
					$session->sendDataPacket(PlayStatusPacket::create(PlayStatusPacket::PLAYER_SPAWN));
					$session->sendDataPacket(StopSoundPacket::create("portal.travel", true, true));
				}

				if (isset($state->timeoutTask)) {
					Server::getInstance()->getScheduler()->cancelTask($state->timeoutTask->getTaskId());
				}
				unset($this->transferring[$name]);
				$this->activeTransferCount--;
			}
		}
	}

	/**
	private function registerKnownCompressor(Compressor $compressor) : void {
		if (isset($this->known_compressors[$id = spl_object_id($compressor)])) {
			return;
		}

		$this->known_compressors[$id] = $compressor;
		foreach (Server::getInstance()->getWorldManager()->getWorlds() as $world) {
			$this->registerHackToWorldIfApplicable($world);
		}
	}

	/**
	 * @return bool True if the hack was applied
	 */
	private function registerHackToWorldIfApplicable(World $world) : bool {
		if (!isset($this->applicable_worlds[$world_name = $world->getFolderName()])) {
			return false;
		}

		$dimension_id = $this->applicable_worlds[$world_name];
		$this->registerHackToWorld($world, $dimension_id);
		return true;
	}

	/**
	 * @param DimensionIds::* $dimension_id
	 */
	private function registerHackToWorld(World $world, int $dimension_id) : void {
		$dimensionProperty = new ReflectionProperty(ChunkCache::class, "dimensionId");
		foreach ($this->known_compressors as $compressor) {
			$chunk_cache = ChunkCache::getInstance($world, $compressor);
			$dimensionProperty->setValue($chunk_cache, $dimension_id);
		}
	}

	/**
	 * @param DimensionIds::* $dimension_id
	 */
	public function applyToWorld(string $world_folder_name, int $dimension_id) : void {
		$this->applicable_worlds[$world_folder_name] = $dimension_id;
		$world = Server::getInstance()->getWorldManager()->getWorldByName($world_folder_name);
		if ($world !== null) {
			$this->registerHackToWorldIfApplicable($world);
		}
	}

	/**
	 * Unregisters the teleport screen effect from the given world and restores
	 * the default dimension used for its chunk cache.
	 */
	public function unapplyFromWorld(string $world_folder_name) : void {
		unset($this->applicable_worlds[$world_folder_name]);

		$world = Server::getInstance()->getWorldManager()->getWorldByName($world_folder_name);
		if ($world !== null) {
			$this->registerHackToWorld($world, DimensionIds::OVERWORLD);
		}
	}

	/**
	 * Sends a teleport screen effect to the given player.
	 *
	 * Uses the Nether -> Overworld dimension change (like HiveMC) to show a loading/portal
	 * screen while chunks load in the background, preventing players from seeing unloaded
	 * chunks during transfers.
	 *
	 * Optimized for 100+ players with configurable chunk radius and transfer rate limiting.
	 */
	public function sendTeleportScreen(Player $player, TransferState $state) : void {
		$plugin = self::getInstance();
		$session = $player->getNetworkSession();
		$name = $player->getName();
		$chunkRadius = $state->chunkRadius;
		$chunkX = (int) ($player->getFloorX() >> 4);
		$chunkZ = (int) ($player->getFloorZ() >> 4);

		if (!$player->isConnected() || !$player->isAlive()) {
			$this->finishTransfer($name);
			return;
		}

		// Step 1: send dimension change to Nether (triggers portal/loading screen on client)
		$session->sendDataPacket(ChangeDimensionPacket::create(
			DimensionIds::NETHER,
			$player->getPosition(),
			false,
			null
		));

		$blockLocation = BlockPosition::fromVector3($player->getLocation());
		$session->sendDataPacket(PlayerActionPacket::create($player->getId(), PlayerAction::DIMENSION_CHANGE_ACK, $blockLocation, $blockLocation, 0));
		$session->sendDataPacket(StopSoundPacket::create("portal.travel", true, true));

		// Step 2: Order chunk population for the configured radius around the player
		// so chunks load while in "Nether". This prevents slow chunk loading on return.
		$onAllChunksLoaded = function () use ($player, $session, $name, $state, $plugin): void {
			if (!$player->isConnected() || !$player->isAlive()) {
				$this->finishTransfer($name);
				return;
			}

			$delay = (int) ($this->config["return-delay-ticks"] ?? 10);
			$plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function () use ($player, $session, $name): void {
				if (!$player->isConnected() || !$player->isAlive()) {
					$this->finishTransfer($name);
					return;
				}

				// Sync all necessary data to avoid visual glitches
				$session->syncGameMode($player->getGamemode());
				$session->syncAbilities($player);
				$session->syncAdventureSettings();
				$session->syncViewAreaRadius($player->getViewDistance());
				$session->syncPlayerSpawnPoint($player->getSpawn());
				$session->syncAvailableCommands();
				$session->onEnterWorld();
				$world = $player->getWorld();
				$session->syncPlayerList($world->getPlayers());

				// Return to Overworld dimension
				$session->sendDataPacket(ChangeDimensionPacket::create(
					DimensionIds::OVERWORLD,
					$player->getPosition(),
					false,
					null
				));

				$session->sendDataPacket(PlayerActionPacket::create(
					$player->getId(),
					PlayerAction::DIMENSION_CHANGE_ACK,
					BlockPosition::fromVector3($player->getLocation()),
					BlockPosition::fromVector3($player->getLocation()),
					0
				));
				$session->sendDataPacket(PlayStatusPacket::create(PlayStatusPacket::PLAYER_SPAWN));
				$session->sendDataPacket(StopSoundPacket::create("portal.travel", true, true));

				$this->finishTransfer($name);
			}), $delay);
		};

		$onChunkError = function () use ($name): void {
			$this->finishTransfer($name);
			$player = Server::getInstance()->getPlayerExact($name);
			if ($player !== null && $player->isConnected() && $player->isAlive()) {
				$player->kick("Failed to load chunks. (Prevent stuck at nether glitch)", null, "You didn't finish joining.");
			}
		};

		$remaining = $state->totalChunks;
		$completionChecker = function () use ($state, $onAllChunksLoaded, $onChunkError, &$remaining): void {
			if ($state->completed) {
				return;
			}
			$remaining--;
			if ($remaining <= 0) {
				$state->completed = true;
				$onAllChunksLoaded();
			}
		};

		for ($dx = -$chunkRadius; $dx <= $chunkRadius; $dx++) {
			for ($dz = -$chunkRadius; $dz <= $chunkRadius; $dz++) {
				$cx = $chunkX + $dx;
				$cz = $chunkZ + $dz;
				$world = $player->getWorld();
				$world->orderChunkPopulation($cx, $cz, null)->onCompletion(
					$completionChecker,
					$onChunkError
				);
			}
		}

		// Set timeout task to prevent stuck transfers
		$timeoutSeconds = (int) ($this->config["transfer-timeout-seconds"] ?? 30);
		$state->timeoutTask = $plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function () use ($name): void {
			if (isset($this->transferring[$name]) && !$this->transferring[$name]->completed) {
				$this->forceFinishTransfer($name);
			}
		}), $timeoutSeconds * 20);
	}

	/**
	 * Mark a transfer as finished and decrement active counter.
	 */
	private function finishTransfer(string $name) : void {
		if (isset($this->transferring[$name])) {
			$state = $this->transferring[$name];
			if (isset($state->timeoutTask)) {
				Server::getInstance()->getScheduler()->cancelTask($state->timeoutTask->getTaskId());
			}
			unset($this->transferring[$name]);
			$this->activeTransferCount = max(0, $this->activeTransferCount - 1);
		}
	}

	/**
	 * Force-finish a stuck transfer (timeout).
	 */
	private function forceFinishTransfer(string $name) : void {
		if (!isset($this->transferring[$name])) {
			return;
		}
		$state = $this->transferring[$name];
		if (isset($state->timeoutTask)) {
			Server::getInstance()->getScheduler()->cancelTask($state->timeoutTask->getTaskId());
		}
		unset($this->transferring[$name]);
		$this->activeTransferCount = max(0, $this->activeTransferCount - 1);

		$player = Server::getInstance()->getPlayerExact($name);
		if ($player !== null && $player->isConnected() && $player->isAlive()) {
			$session = $player->getNetworkSession();
			$session->sendDataPacket(ChangeDimensionPacket::create(
				DimensionIds::OVERWORLD,
				$player->getPosition(),
				false,
				null
			));
			$session->sendDataPacket(PlayerActionPacket::create(
				$player->getId(),
				PlayerAction::DIMENSION_CHANGE_ACK,
				BlockPosition::fromVector3($player->getLocation()),
				BlockPosition::fromVector3($player->getLocation()),
				0
			));
			$session->sendDataPacket(PlayStatusPacket::create(PlayStatusPacket::PLAYER_SPAWN));
			$session->sendDataPacket(StopSoundPacket::create("portal.travel", true, true));
		}
	}

	private function isPlayerConnected(Player $player) : bool {
		return $player->isConnected();
	}

	private function isPlayerAlive(Player $player) : bool {
		return $player->isAlive();
	}
}
