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

namespace ReinfyTeam\FakeWaterdogTransfer\Config;

use pocketmine\plugin\PluginBase;
use function max;
use function min;

final class TransferConfig {
	public function __construct(
		private readonly PluginBase $plugin,
	) {
	}

	private function getConfigInt(string $key, int $default) : int {
		$value = $this->plugin->getConfig()->get($key, $default);

		return is_int($value) ? $value : $default;
	}

	private function getConfigBool(string $key, bool $default) : bool {
		$value = $this->plugin->getConfig()->get($key, $default);

		return is_bool($value) ? $value : $default;
	}

	private function getConfigString(string $key, string $default) : string {
		$value = $this->plugin->getConfig()->get($key, $default);

		return is_string($value) ? $value : $default;
	}

	public function getMaxConcurrentTransfers() : int {
		return max(1, min(100, $this->getConfigInt("max-concurrent-transfers", 15)));
	}

	public function getConfiguredChunkRadius() : int {
		return max(1, min(5, $this->getConfigInt("chunk-radius", 2)));
	}

	public function getReturnDelayTicks() : int {
		return max(1, min(100, $this->getConfigInt("return-delay-ticks", 1)));
	}

	public function getTransferTimeoutSeconds() : int {
		return max(5, min(300, $this->getConfigInt("transfer-timeout-seconds", 30)));
	}

	public function getTransferChunksPerTick() : int {
		return max(4, min(128, $this->getConfigInt("transfer-chunks-per-tick", 8)));
	}

	public function getForcedChunkRadius() : int {
		return max(1, min(32, $this->getConfigInt("forced-chunk-radius", 8)));
	}

	public function getForcedChunksPerTick() : int {
		return max(4, min(128, $this->getConfigInt("forced-chunks-per-tick", 32)));
	}

	public function getForceWorldChunkLoading() : bool {
		return $this->getConfigBool("force-world-chunk-loading", true);
	}

	public function getTemporaryViewDistanceBoost() : bool {
		return $this->getConfigBool("temporary-view-distance-boost", true);
	}

	public function getWaitForFullViewDistance() : bool {
		return $this->getConfigBool("wait-for-full-view-distance", true);
	}

	public function getKickStuckPlayers() : bool {
		return $this->getConfigBool("kick-stuck-players", true);
	}

	public function getKickMessage() : string {
		return $this->getConfigString(
			"kick-message",
			"§cYou got stuck during world transfer. Please reconnect."
		);
	}

	public function getKickStuckAfterSeconds() : int {
		return max(5, min(300, $this->getConfigInt("kick-stuck-after-seconds", 15)));
	}
}
