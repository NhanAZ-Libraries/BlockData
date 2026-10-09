# BlockData

BlockData is a virion that stores persistent JSON data at block coordinates for Axolotl-PM plugins.

[![Validate](https://github.com/NhanAZ-Libraries/BlockData/actions/workflows/ci.yml/badge.svg)](https://github.com/NhanAZ-Libraries/BlockData/actions/workflows/ci.yml)

## Overview

Each plugin using BlockData gets a separate LevelDB database for each world under its data folder. Values are cached while their chunk is loaded. Pending writes are flushed on world save, chunk unload, or plugin disable.

BlockData is a library for plugin authors. It cannot be installed as a standalone server plugin.

## Features

- Store JSON-compatible strings, numbers, booleans, and arrays at block coordinates.
- Read and change data through block objects or world coordinates.
- Optionally remove data when common block destruction events occur.
- Keep each plugin's data in its own per-world LevelDB database.

## Requirements and compatibility

- The virion declares Axolotl-PM plugin API 5.0.0. The exact tested server revision is recorded in the locked development dependency and [CI workflow](.github/workflows/ci.yml).
- PHP 8.1 or newer, with the JSON and LevelDB extensions supplied by the server.
- [DevTools 1.0.1](https://github.com/NhanAZ/DevTools/releases/tag/v1.0.1) for folder development and shaded PHAR builds.

The minimum server patch release has not been established independently. Runtime compatibility across all Axolotl-PM 5.x releases has not been tested.

## Installation

Check out this repository into `virions/BlockData` next to the host plugin's source folder. Pin the checkout to a reviewed commit in CI. Declare the dependency in the host plugin's `devtools.yml`.

```yaml
virions:
  - name: BlockData
    version: ^1.0.0
```

For a folder plugin, place the official DevTools PHAR in `plugins/`. DevTools loads the virion from `virions/BlockData` during development and shades it into the host plugin during a PHAR build. The [BlockDataExample workflow](https://github.com/NhanAZ-Plugins/BlockDataExample/blob/master/.github/workflows/build.yml) shows a complete checkout and build.

## Usage

Create one BlockData instance in the host plugin's `onEnable()` method.

```php
use NhanAZ\BlockData\BlockData;

$this->blockData = BlockData::create($this);
$this->blockData->setAt($world, 10, 64, 20, ['owner' => 'Alice']);
$owner = $this->blockData->getAt($world, 10, 64, 20);
$exists = $this->blockData->hasAt($world, 10, 64, 20);
$this->blockData->removeAt($world, 10, 64, 20);
```

The `set()`, `get()`, `has()`, and `remove()` methods accept a `Block` instead of a world and coordinates. `setAt()` rejects `null` and values that JSON cannot encode. Use `removeAt()` to delete a value. A missing value is returned as `null`.

To remove stored data after common block destruction events, enable the optional listener.

```php
$this->blockData = BlockData::create($this, autoCleanup: true);
```

The listener handles player block breaks, explosions, fire, and leaf decay. Changes made by other plugins without those events need explicit calls to `remove()` or `removeAt()`. See [issue 8](https://github.com/NhanAZ-Libraries/BlockData/issues/8) for the event coverage discussion.

To choose another storage location, pass `dataPath` to `create()`.

```php
$this->blockData = BlockData::create(
    $this,
    dataPath: $this->getDataFolder() . 'custom-blockdata'
);
```

BlockData appends the world folder name to that path. Keep a backup of the directory before changing the path, since BlockData does not migrate existing databases automatically.

## Building with DevTools

Run `/devtools doctor YourPlugin` to check the folder project, then `/devtools build YourPlugin` to create its PHAR. The built PHAR contains a private shaded copy of BlockData. A production server needs neither DevTools nor a separate BlockData installation.

Do not copy this library directly into the server's `plugins/` directory. See [BlockDataExample](https://github.com/NhanAZ-Plugins/BlockDataExample) for a runnable host plugin.

## Development and testing

The repository uses a locked Axolotl-PM development dependency in `composer.lock`. Run the same checks used by CI after installing the required PHP extensions.

```sh
composer install --no-interaction --prefer-dist --no-progress --no-scripts --no-plugins
composer validate --strict
composer lint
composer phpstan
composer test
```

The storage test uses a temporary LevelDB database. It checks persistence across reopening, removal, and retention of pending data when a chunk flush fails. PHPStan runs at level max.

See the [changelog](CHANGELOG.md) for version changes and rollback guidance.

## License and credits

BlockData is licensed under [LGPL-3.0-or-later](LICENSE). Its design was inspired by [Cosmoverse/BlockData](https://github.com/Cosmoverse/BlockData). This repository is maintained by [NhanAZ-Libraries](https://github.com/NhanAZ-Libraries).

Report defects in [GitHub Issues](https://github.com/NhanAZ-Libraries/BlockData/issues). Community support is available through [NhanAZ Discord](https://discord.gg/j2X83ujT6c).
