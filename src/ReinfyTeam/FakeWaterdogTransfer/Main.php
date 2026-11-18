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

		$this->registerKnownCompressor(ZlibCompressor::getInstance());
	}

	protected function onDisable() : void {
		$this->transferManager->onDisable();
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
