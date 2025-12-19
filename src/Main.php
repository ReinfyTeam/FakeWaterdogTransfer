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
use pocketmine\world\format\Chunk;
use pocketmine\world\World;
use ReflectionProperty;
use function spl_object_id;

final class Main extends PluginBase {
	/** @var array<string, DimensionIds::*> */
	private array $applicable_worlds = [];

	/** @var Compressor[] */
	private array $known_compressors = [];

	public function onEnable() : void {
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

			$from = $event->getFrom()->getWorld();
			$to = $event->getTo()->getWorld();

			// only run effect if teleporting between worlds
			if ($from->getFolderName() === $to->getFolderName()) {
				return;
			}

			$this->sendTeleportScreen($entity);
		}, EventPriority::LOWEST, $this);
	}

	/**
	 * @internal
	 */
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
		static $_dimension_id = new ReflectionProperty(ChunkCache::class, "dimensionId");
		foreach ($this->known_compressors as $compressor) {
			$chunk_cache = ChunkCache::getInstance($world, $compressor);
			$_dimension_id->setValue($chunk_cache, $dimension_id);
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
	 * Unregisters the teleport screen effect from the given world.
	 */
	public function unapplyFromWorld(string $world_folder_name) : void {
		unset($this->applicable_worlds[$world_folder_name]);
	}

	/**
	 * Sends a teleport screen effect to the given player.
	 */
	public function sendTeleportScreen(Player $player) : void {
		$plugin = Lobby::getInstance();
		$session = $player->getNetworkSession();

		if (!$player->isConnected() || !$player->isAlive()) {
			return;
		}

		$position = $player->getPosition();
		$blockLocation = BlockPosition::fromVector3($player->getLocation());
		$world = $player->getWorld();

		// Step 1: send dimension change to a different dimension (Nether)
		$session->sendDataPacket(ChangeDimensionPacket::create(
			DimensionIds::NETHER,
			$position,
			false,
			null
		));

		$player->getNetworkSession()->sendDataPacket(PlayerActionPacket::create($player->getId(), PlayerAction::DIMENSION_CHANGE_ACK, $blockLocation, $blockLocation, 0));
		$session->sendDataPacket(StopSoundPacket::create("portal.travel", true, true));

		$x = $position->getFloorX() >> 4;
		$z = $position->getFloorZ() >> 4;
		$world->orderChunkPopulation($x, $z, null)->onCompletion(
			function(Chunk $chunk) use ($player, $x, $z, $world, $plugin, $session, $blockLocation) : void {
				if (!$player->isConnected()) {
					return;
				}
				// Step 2: after short delay, return to actual overworld
				$plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player, $session, $world, $blockLocation) : void {
					if (!$player->isConnected() || !$player->isAlive()) {
						return;
					}
					// Sync all necessary data to avoid visual glitches
					$player->getNetworkSession()->syncGameMode($player->getGamemode());
					$player->getNetworkSession()->syncAbilities($player);
					$player->getNetworkSession()->syncAdventureSettings();
					$player->getNetworkSession()->syncViewAreaRadius($player->getViewDistance());
					$player->getNetworkSession()->syncPlayerSpawnPoint($player->getSpawn());
					$player->getNetworkSession()->syncAvailableCommands();
					$player->getNetworkSession()->onEnterWorld();
					$player->getNetworkSession()->syncPlayerList($world->getPlayers());

					$session->sendDataPacket(ChangeDimensionPacket::create(
						DimensionIds::OVERWORLD,
						$player->getPosition(),
						false,
						null
					));

					$player->getNetworkSession()->sendDataPacket(PlayerActionPacket::create($player->getId(), PlayerAction::DIMENSION_CHANGE_ACK, $blockLocation, $blockLocation, 0));
					$session->sendDataPacket(PlayStatusPacket::create(PlayStatusPacket::PLAYER_SPAWN));
					$session->sendDataPacket(StopSoundPacket::create("portal.travel", true, true));
				}), 10);
			},
			function() {
				// just incase the player is stuck (nether glitch).
				$player->kick("Failed to load chunks. (Prevent stuck at nether glitch)", null, "You didn't finish joining.");
			}
		);
	}
}