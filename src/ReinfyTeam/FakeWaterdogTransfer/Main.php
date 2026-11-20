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
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\server\LowMemoryEvent;
use pocketmine\event\world\WorldLoadEvent;
use pocketmine\network\mcpe\cache\ChunkCache;
use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\network\mcpe\protocol\MovePlayerPacket;
use pocketmine\network\mcpe\protocol\PlayerActionPacket;
use pocketmine\network\mcpe\protocol\PlayerAuthInputPacket;
use pocketmine\network\mcpe\protocol\PlayStatusPacket;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\network\mcpe\protocol\types\PlayerAction;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\SingletonTrait;
use pocketmine\world\World;
use ReflectionProperty;
use ReinfyTeam\FakeWaterdogTransfer\Chunk\ChunkForcer;
use ReinfyTeam\FakeWaterdogTransfer\Config\TransferConfig;
use ReinfyTeam\FakeWaterdogTransfer\Transfer\TransferManager;
use function spl_object_id;

final class Main extends PluginBase {
	use SingletonTrait;

	/** @var array<int, Compressor> */
	private array $knownCompressors = [];

	private TransferManager $transferManager;
	private TransferConfig $config;
	private ChunkForcer $chunkForcer;

	private ?ReflectionProperty $dimensionIdProperty = null;

	/** @var array<string, bool> Player name => is in dimension transition */
	private array $playersInTransition = [];

	protected function onLoad() : void {
		self::setInstance($this);
	}

	protected function onEnable() : void {
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
			function(PlayerLoginEvent $event) : void {
				$this->registerKnownCompressor(
					$event->getPlayer()->getNetworkSession()->getCompressor()
				);
			},
			EventPriority::LOWEST,
			$this
		);

		$pluginManager->registerEvent(
			WorldLoadEvent::class,
			function(WorldLoadEvent $event) : void {
				$this->registerHackToWorldIfApplicable($event->getWorld());
			},
			EventPriority::LOWEST,
			$this
		);

		$pluginManager->registerEvent(
			LowMemoryEvent::class,
			function(LowMemoryEvent $event) : void {
				foreach ($this->getServer()->getWorldManager()->getWorlds() as $world) {
					$this->registerHackToWorldIfApplicable($world);
				}
			},
			EventPriority::LOWEST,
			$this
		);

		$pluginManager->registerEvent(
			EntityTeleportEvent::class,
			function(EntityTeleportEvent $event) : void {
				$this->transferManager->handleTeleport($event);
			},
			EventPriority::MONITOR,
			$this
		);

		$pluginManager->registerEvent(
			PlayerQuitEvent::class,
			function(PlayerQuitEvent $event) : void {
				$this->transferManager->handlePlayerQuit($event);
			},
			EventPriority::MONITOR,
			$this
		);

		// Intercept incoming packets during dimension transitions.
		// Blocks MovePlayerPacket and PlayerAuthInputPacket while a player is
		// transferring, preventing client/server movement desync.
		$pluginManager->registerEvent(
			DataPacketReceiveEvent::class,
			function(DataPacketReceiveEvent $event) : void {
				$this->onPacketReceive($event);
			},
			EventPriority::MONITOR,
			$this
		);

		$this->registerKnownCompressor(ZlibCompressor::getInstance());
	}

	protected function onDisable() : void {
		$this->transferManager->onDisable();
	}

	/**
	 * Intercepts DIMENSION_CHANGE_ACK (action 14) from the client.
	 *
	 * PocketMine-MP rejects action type 14, which leaves the player's
	 * movement locked (no client predictions flag) after a simulated
	 * dimension switch.
	 *
	 * We distinguish between:
	 *   - Fake Nether ACK: acknowledged during the loading screen. Does NOT
	 *     unlock movement — chunks may still be loading.
	 *   - Real Overworld ACK: acknowledged after the real destination
	 *     dimension change. Unlocks movement and completes the transfer.
	 */
	public function onPacketReceive(DataPacketReceiveEvent $event) : void {
		$packet = $event->getPacket();
		$player = $event->getOrigin()->getPlayer();

		if ($player === null) {
			return;
		}

		$name = $player->getName();

		// Block movement packets during transitions to prevent
		// client/server movement desync (rubberbanding).
		if ($packet instanceof MovePlayerPacket || $packet instanceof PlayerAuthInputPacket) {
			if (isset($this->playersInTransition[$name])) {
				$event->cancel();
			}
			return;
		}

		if (!$packet instanceof PlayerActionPacket) {
			return;
		}

		if ($packet->action !== PlayerAction::DIMENSION_CHANGE_ACK) {
			return;
		}

		// Cancel so PocketMine-MP does not try to process/reject this action.
		$event->cancel();

		if (!isset($this->playersInTransition[$name])) {
			return;
		}

		// Determine whether this ACK is for the fake Nether screen or the
		// real destination dimension change.
		$state = $this->getTransferState($name);
		if ($state === null) {
			return;
		}

		if (!$state->dimensionChangeSent) {
			// This is the fake Nether acknowledgement. Do NOT unlock movement
			// — chunks may still be loading.
			$this->transferManager->markFakeNetherAckReceived($name);
			return;
		}

		if (!$state->realDimensionAckReceived) {
			// This is the REAL dimension acknowledgement. Unlock movement
			// immediately and resend spawn.
			$this->transferManager->markRealDimensionAckReceived($name);

			// Unlock movement immediately.
			$player->setNoClientPredictions(false);

			// Resend spawn so the client recognizes movement capability right away.
			$player->getNetworkSession()->sendDataPacket(
				PlayStatusPacket::create(PlayStatusPacket::PLAYER_SPAWN)
			);
		}
	}

	/**
	 * Gets the TransferState for a player, or null if not in a transfer.
	 */
	private function getTransferState(string $name) : ?\ReinfyTeam\FakeWaterdogTransfer\Transfer\TransferState {
		return $this->transferManager->getTransferState($name);
	}

	/**
	 * Marks a player as being in a dimension transition.
	 * Movement packets will be blocked until markTransitionComplete() is called.
	 */
	public function markTransitionStart(string $name) : void {
		$this->playersInTransition[$name] = true;
	}

	/**
	 * Marks a player's dimension transition as complete.
	 * Movement packets will no longer be blocked.
	 */
	public function markTransitionComplete(string $name) : void {
		unset($this->playersInTransition[$name]);
	}

	private function initializeReflectionProperties() : void {
		$this->dimensionIdProperty = new ReflectionProperty(
			ChunkCache::class,
			"dimensionId"
		);
	}

	private function registerKnownCompressor(Compressor $compressor) : void {
		$id = spl_object_id($compressor);

		if (isset($this->knownCompressors[$id])) {
			return;
		}

		$this->knownCompressors[$id] = $compressor;

		foreach ($this->getServer()->getWorldManager()->getWorlds() as $world) {
			$this->registerHackToWorldIfApplicable($world);
		}
	}

	private function registerHackToWorldIfApplicable(World $world) : bool {
		$folderName = $world->getFolderName();

		if (!isset($this->applicableWorlds[$folderName])) {
			return false;
		}

		$dimensionId = $this->applicableWorlds[$folderName];
		if ($dimensionId < DimensionIds::OVERWORLD || $dimensionId > DimensionIds::THE_END) {
			return false;
		}

		$this->registerHackToWorld($world, $dimensionId);
		return true;
	}

	/**
	 * @param DimensionIds::* $dimensionId
	 */
	private function registerHackToWorld(World $world, int $dimensionId) : void {
		if ($this->dimensionIdProperty === null) {
			return;
		}

		foreach ($this->knownCompressors as $compressor) {
			$chunkCache = ChunkCache::getInstance($world, $compressor);
			$this->dimensionIdProperty->setValue($chunkCache, $dimensionId);
		}
	}

	/**
	 * Maps a world folder to the dimension ID used in that world's chunk cache.
	 *
	 * @param DimensionIds::* $dimensionId
	 */
	public function applyToWorld(string $worldFolderName, int $dimensionId) : void {
		$this->applicableWorlds[$worldFolderName] = $dimensionId;
		$this->transferManager->applyToWorld($worldFolderName, $dimensionId);

		$world = $this->getServer()->getWorldManager()->getWorldByName($worldFolderName);
		if ($world !== null) {
			$this->registerHackToWorldIfApplicable($world);
		}
	}

	/**
	 * Removes the custom mapping and restores the OVERWORLD chunk-cache ID.
	 */
	public function unapplyFromWorld(string $worldFolderName) : void {
		unset($this->applicableWorlds[$worldFolderName]);
		$this->transferManager->unapplyFromWorld($worldFolderName);

		$world = $this->getServer()->getWorldManager()->getWorldByName($worldFolderName);
		if ($world !== null) {
			$this->registerHackToWorld($world, DimensionIds::OVERWORLD);
		}
	}

	/** @var array<string, int> World folder => dimension ID */
	private array $applicableWorlds = [];
}
