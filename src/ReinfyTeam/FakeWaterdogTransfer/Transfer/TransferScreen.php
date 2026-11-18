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

use pocketmine\network\mcpe\protocol\ChangeDimensionPacket;
use pocketmine\network\mcpe\protocol\PlayStatusPacket;
use pocketmine\network\mcpe\protocol\StopSoundPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\player\Player;

/**
 * Sends fake dimension-change transition screens so the client doesn't render
 * the destination world until chunks are ready.
 *
 * NOTE: PlayerAction::DIMENSION_CHANGE_ACK is a client-to-server packet and
 * MUST NOT be fabricated or sent by the server. It is intentionally omitted
 * here.
 */
final class TransferScreen {
	/**
	 * Shows the fake Nether transition screen so the client doesn't render
	 * the destination world until chunks are ready.
	 */
	public static function showFakeNetherScreen(Player $player) : void {
		self::sendDimensionChangeScreen($player, DimensionIds::NETHER);
	}

	/**
	 * Sends a fake dimension-change screen (ChangeDimensionPacket +
	 * StopSoundPacket) for the given dimension ID.
	 *
	 * This makes the client show a portal transition without actually
	 * changing the server-side dimension.
	 */
	private static function sendDimensionChangeScreen(Player $player, int $dimensionId) : void {
		$session = $player->getNetworkSession();
		$position = $player->getPosition();
		$blockPosition = BlockPosition::fromVector3($player->getLocation());

		$session->sendDataPacket(
			ChangeDimensionPacket::create(
				$dimensionId,
				$position,
				false,
				null
			)
		);

		$session->sendDataPacket(
			StopSoundPacket::create("portal.travel", true, true)
		);
	}

	/**
	 * Sends only the real ChangeDimensionPacket to the destination dimension.
	 * No spawn packets — the client needs one tick to process the dimension
	 * change before spawning, otherwise it can get stuck.
	 */
	public static function sendRealDimensionChange(Player $player, int $dimensionId) : void {
		$session = $player->getNetworkSession();
		$position = $player->getPosition();

		$session->sendDataPacket(
			ChangeDimensionPacket::create(
				$dimensionId,
				$position,
				false,
				null
			)
		);
	}

	/**
	 * Spawns the player in the destination dimension after the
	 * ChangeDimensionPacket has been processed by the client.
	 * Sends all sync packets + PLAYER_SPAWN.
	 */
	public static function spawnPlayer(Player $player, int $dimensionId) : void {
		$session = $player->getNetworkSession();
		$world = $player->getWorld();
		$position = $player->getPosition();
		$blockPosition = BlockPosition::fromVector3($player->getLocation());

		$session->syncGameMode($player->getGamemode());
		$session->syncAbilities($player);
		$session->syncAdventureSettings();
		$session->syncViewAreaRadius($player->getViewDistance());
		$session->syncPlayerSpawnPoint($player->getSpawn());
		$session->syncAvailableCommands();
		$session->onEnterWorld();
		$session->syncPlayerList($world->getPlayers());

		$session->sendDataPacket(
			PlayStatusPacket::create(PlayStatusPacket::PLAYER_SPAWN)
		);

		$session->sendDataPacket(
			StopSoundPacket::create("portal.travel", true, true)
		);
	}

	/**
	 * Restores the real destination dimension and lets the client spawn.
	 *
	 * If $dimensionChangeSent is true, the ChangeDimensionPacket was already
	 * sent in a previous tick, so only spawn packets are sent.
	 *
	 * @param bool $dimensionChangeSent Whether ChangeDimensionPacket was already sent
	 */
	public static function restoreTargetDimension(Player $player, int $dimensionId, bool $dimensionChangeSent = false) : void {
		if (!$dimensionChangeSent) {
			self::sendRealDimensionChange($player, $dimensionId);
		}

		self::spawnPlayer($player, $dimensionId);
	}

	/**
	 * Returns the Bedrock dimension ID mapped to the given world folder,
	 * defaulting to OVERWORLD.
	 *
	 * @param array<string, int> $applicableWorlds World folder => dimension ID
	 */
	public static function getDimensionIdForWorld(array $applicableWorlds, string $folderName) : int {
		return $applicableWorlds[$folderName] ?? DimensionIds::OVERWORLD;
	}
}
