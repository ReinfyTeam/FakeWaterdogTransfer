<?php

declare(strict_types=1);

namespace ReinfyTeam\FakeWaterdogTransfer\Transfer;

use pocketmine\network\mcpe\protocol\ChangeDimensionPacket;
use pocketmine\network\mcpe\protocol\PlayStatusPacket;
use pocketmine\network\mcpe\protocol\PlayerActionPacket;
use pocketmine\network\mcpe\protocol\StopSoundPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\network\mcpe\protocol\types\PlayerAction;
use pocketmine\player\Player;
use pocketmine\network\mcpe\NetworkSession;

final class TransferScreen{
    /**
     * Shows the fake Nether transition screen so the client doesn't render
     * the destination world until chunks are ready.
     */
    public static function showFakeNetherScreen(Player $player) : void{
        $session = $player->getNetworkSession();
        $position = $player->getPosition();
        $blockPosition = BlockPosition::fromVector3($player->getLocation());

        $session->sendDataPacket(
            ChangeDimensionPacket::create(
                DimensionIds::NETHER,
                $position,
                false,
                null
            )
        );

        $session->sendDataPacket(
            PlayerActionPacket::create(
                $player->getId(),
                PlayerAction::DIMENSION_CHANGE_ACK,
                $blockPosition,
                $blockPosition,
                0
            )
        );

        $session->sendDataPacket(
            StopSoundPacket::create("portal.travel", true, true)
        );
    }

    /**
     * Restores the real destination dimension and lets the client spawn.
     */
    public static function restoreTargetDimension(Player $player, int $dimensionId) : void{
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
            ChangeDimensionPacket::create(
                $dimensionId,
                $position,
                false,
                null
            )
        );

        $session->sendDataPacket(
            PlayerActionPacket::create(
                $player->getId(),
                PlayerAction::DIMENSION_CHANGE_ACK,
                $blockPosition,
                $blockPosition,
                0
            )
        );

        $session->sendDataPacket(
            PlayStatusPacket::create(PlayStatusPacket::PLAYER_SPAWN)
        );

        $session->sendDataPacket(
            StopSoundPacket::create("portal.travel", true, true)
        );
    }

    /**
     * Returns the Bedrock dimension ID mapped to the given world folder,
     * defaulting to OVERWORLD.
     */
    public static function getDimensionIdForWorld(array $applicableWorlds, string $folderName) : int{
        return $applicableWorlds[$folderName] ?? DimensionIds::OVERWORLD;
    }
}
