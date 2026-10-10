# FakeWaterdogTransfer

FakeWaterdogTransfer displays a fake teleport screen when a player changes worlds. It is inspired by the transfer screen used by WaterdogPE and is maintained by ReinfyTeam for PocketMine-MP servers.

![FakeWaterdogTransfer preview](https://github.com/user-attachments/assets/59fffd1b-64f7-47a2-b16c-c83cdb6ab890)

## Requirements

- PocketMine-MP API `5.0.0` or newer.
- A world-management plugin or another server feature that changes the player's world.
- PHP 8.1 or newer for local development and packaging.

The plugin is intended for PocketMine-MP. Compatibility with unofficial server variants is not promised. The campaign verification environment tested the packaged plugin on Axolotl-PM `5.49.1`; that is a tested environment, not a claim that every 5.x release is supported.

## Installation

1. Download the latest compatible `FakeWaterdogTransfer.phar` from [Poggit](https://poggit.pmmp.io/p/FakeWaterdogTransfer) or a ReinfyTeam release when one is available.
2. Copy the PHAR into the server's `plugins/` directory.
3. Start the server and review the startup log for the plugin enable message.
4. Change a player's world through the relevant world-management plugin and confirm that the expected transfer screen is displayed.

## Compatibility

FakeWaterdogTransfer listens for world changes and presents a visual transfer-screen effect to the player. It does not replace the world-management plugin that performs the teleport or world change.

The plugin manifest declares API `5.0.0`. Minimum API, supported range and tested server versions are separate claims; consult the release notes and verification records for exact build and runtime evidence.

## Build and test

Install the locked development dependencies and run the available checks:

```sh
composer install
composer analyze
composer build
```

The build workflow uses [DevTools](https://github.com/NhanAZ/DevTools) to package and validate the plugin PHAR. The workflow pins the builder revision used by CI; this user-facing guide names the tool without copying that CI pin.

Before using a locally built artifact, inspect its manifest and load it in an isolated PocketMine-MP-compatible server data directory. Do not overwrite a live server world, `plugin_data` directory or database during testing.

## Releases

The repository's release workflow builds a verified PHAR when the commit subject matches `Release x.y.z` and creates a draft GitHub release for review. Keep release commits focused, review the generated artifact and publish only after the relevant runtime checks pass.

## Support and contributing

For reproducible bugs, open an [issue](https://github.com/ReinfyTeam/FakeWaterdogTransfer/issues) with the plugin version, server version, relevant log output and steps to reproduce the problem.

For community support, join the [ReinfyTeam Discord server](https://discord.com/invite/7u7qKsvSxg). Please do not report behavior from modified installations as if it came from an official release artifact.

## Credits and license

FakeWaterdogTransfer is maintained by ReinfyTeam. The project is licensed under [LGPL-2.1-only](LICENSE).
