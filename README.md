# BlockData

A high-performance, developer-friendly virion for storing custom data on blocks in PocketMine-MP.

**Store any data on any block** — strings, numbers, arrays — with just 3 methods: `set()`, `get()`, `remove()`.

## Features

- **Simple API** — No custom classes, no type registration, no boilerplate
- **High Performance** — LevelDB backend with Snappy compression and in-memory caching
- **Store Anything** — Any JSON-serializable value (string, int, float, bool, array)
- **Auto Cleanup** — Optionally auto-remove data when blocks are destroyed
- **Chunk-Aware** — Cache eviction follows chunk lifecycle to prevent memory leaks
- **Multi-Plugin Safe** — Each plugin gets its own isolated LevelDB database

## Installation

**Composer:**
```bash
composer require nhanaz/blockdata
```

**Poggit Virion** — add to your `.poggit.yml`:
```yaml
libs:
  - src: NhanAZ-Libraries/BlockData/BlockData
    version: ^1.0.0
```

## Quick Start

```php
use NhanAZ\BlockData\BlockData;
use pocketmine\plugin\PluginBase;

class MyPlugin extends PluginBase {

    private BlockData $blockData;

    protected function onEnable(): void {
        // One-line setup — that's it!
        $this->blockData = BlockData::create($this);
    }
}
```

#### Store data

```php
// Store an array
$this->blockData->set($block, [
    "owner" => $player->getName(),
    "placed_at" => time(),
    "protected" => true,
]);

// Store a simple string
$this->blockData->set($block, "Hello World");

// Store a number
$this->blockData->set($block, 42);
```

#### Read data

```php
$data = $this->blockData->get($block);

if ($data !== null) {
    // Data exists! Use it.
    $player->sendMessage("Owner: " . $data["owner"]);
}

// Or use has() to check existence
if ($this->blockData->has($block)) {
    // ...
}
```

#### Remove data

```php
$this->blockData->remove($block);
```

#### Coordinate-based access

If you have coordinates instead of a Block object:

```php
$this->blockData->setAt($world, $x, $y, $z, ["key" => "value"]);
$data = $this->blockData->getAt($world, $x, $y, $z);
$exists = $this->blockData->hasAt($world, $x, $y, $z);
$this->blockData->removeAt($world, $x, $y, $z);
```

## Auto Cleanup

Enable automatic data removal when blocks are destroyed:

```php
$this->blockData = BlockData::create($this, autoCleanup: true);
```

When enabled, block data is automatically removed on:
- **Player breaks** the block (`BlockBreakEvent`)
- **Explosions** destroy the block (`EntityExplodeEvent`)
- **Fire** burns the block (`BlockBurnEvent`)
- **Leaves** decay naturally (`LeavesDecayEvent`)

If you need custom logic (e.g. transfer data to drops), use `autoCleanup: false` and handle events yourself.

> **Note:** Auto cleanup is *event-based* and only listens to common PocketMine events.  
> If other plugins modify or remove blocks in custom ways without firing these events, BlockData cannot automatically detect those changes. In that case, you should manually remove or update block data in your own code.  
> See [Issue #8 – Event-Based Block Tracking Issues](https://github.com/NhanAZ-Libraries/BlockData/issues/8) for discussion and rationale.

## Custom Data Path

By default, BlockData stores its databases under your plugin's data folder in `blockdata/<worldFolderName>`.
You can override the base path by passing a custom `$dataPath`:

```php
// Store data under a custom directory
$this->blockData = BlockData::create(
    $this,
    autoCleanup: true,
    dataPath: $this->getServer()->getDataPath() . "custom-blockdata"
);
```

This will create per-world LevelDB databases under:

```text
<dataPath>/<worldFolderName>
```

## Example Plugin

See [BlockDataExample](https://github.com/NhanAZ-Plugins/BlockDataExample) for a full working plugin that demonstrates:
- Saving block ownership on place
- Protecting blocks from unauthorized breaking
- Inspecting block info with right-click + `/inspect` command

## API Reference

### `BlockData::create(PluginBase $plugin, bool $autoCleanup = false, ?string $dataPath = null): BlockData`

Creates a new BlockData instance. Call once in `onEnable()`.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `$plugin` | `PluginBase` | *(required)* | Your plugin instance |
| `$autoCleanup` | `bool` | `false` | Auto-remove data when blocks are destroyed |
| `$dataPath` | `string\|null` | `null` | Base directory for storing per-world LevelDB databases. Defaults to `<plugin data folder>/blockdata`. |

### Block Methods

| Method | Returns | Description |
|--------|---------|-------------|
| `set(Block $block, mixed $data)` | `void` | Store data for a block |
| `get(Block $block)` | `mixed` | Get stored data, or `null` |
| `has(Block $block)` | `bool` | Check if data exists |
| `remove(Block $block)` | `void` | Remove stored data |

### Coordinate Methods

| Method | Returns | Description |
|--------|---------|-------------|
| `setAt(World, int $x, int $y, int $z, mixed $data)` | `void` | Store data at coordinates |
| `getAt(World, int $x, int $y, int $z)` | `mixed` | Get data at coordinates |
| `hasAt(World, int $x, int $y, int $z)` | `bool` | Check data at coordinates |
| `removeAt(World, int $x, int $y, int $z)` | `void` | Remove data at coordinates |

### Data Types

You can store any JSON-serializable value:

```php
$blockData->set($block, "a string");           // string
$blockData->set($block, 42);                   // int
$blockData->set($block, 3.14);                 // float
$blockData->set($block, true);                 // bool
$blockData->set($block, ["key" => "value"]);   // array
$blockData->set($block, [1, 2, 3]);            // list
$blockData->set($block, [                      // nested
    "stats" => ["hp" => 100, "mp" => 50],
    "items" => ["sword", "shield"],
]);
```

## Architecture

```
Your Plugin
    |
    v
BlockData          <- Simple public API (set/get/has/remove)
    |
    v
BlockDataWorld     <- Per-world LevelDB + lazy cache + batch writes
    |
    v
BlockDataListener  <- Chunk lifecycle + optional auto-cleanup
```

- **Storage**: LevelDB with Snappy compression (same engine Minecraft Bedrock uses)
- **Caching**: Lazy read-through cache, evicted on chunk unload
- **Writes**: Batched via `LevelDBWriteBatch`, flushed on save or chunk unload
- **Serialization**: JSON (human-debuggable, universally understood)
- **Isolation**: Each plugin gets its own LevelDB database per world

## Comparison with Cosmoverse/BlockData

This library is heavily inspired by `Cosmoverse/BlockData`, but focuses more on **developer experience** while keeping similar performance characteristics.

| Aspect | This Library (`NhanAZ/BlockData`) | Cosmoverse/BlockData | Notes |
|--------|-----------------------------------|-----------------------|-------|
| **Storage backend** | LevelDB + Snappy | LevelDB + Snappy | Same engine, similar raw performance |
| **Serialization format** | JSON (text) | NBT (binary) | NBT is slightly more compact; JSON is easier to debug and reason about |
| **API to start using** | `BlockData::create($this);` | Create manager + register custom class + factory type | This lib removes the need for custom classes and manual type registration |
| **Store data on a block** | `$blockData->set($block, $data);` | `$manager->get($world)->setBlockDataAt($x, $y, $z, new MyData(...));` | Single flat API vs nested world/manager calls |
| **Data model** | One JSON-serializable blob per block | One or more typed `BlockData` objects per block | Cosmoverse is more flexible for multi-type per block; this library is simpler and covers most plugin use cases |
| **Type safety** | Dynamic (runtime JSON validation) | Static via `BlockData` interface | Cosmoverse enforces stricter contracts; this library trades some strictness for simplicity |
| **Auto cleanup** | Built-in for 4 events (break, explode, burn, leaves decay) | Not built-in (plugin must handle) | Reduces boilerplate for common scenarios |
| **Learning curve** | Very low (3 core methods) | Medium (NBT, factories, custom classes) | Designed to be friendly for non-expert developers and editors |
| **Extensibility** | Easy to extend by adding more keys to the stored array | Easy to extend by adding more fields to NBT classes | Choice between “just add another array key” vs creating/updating a class |
| **Best fit** | Simple–medium plugins that want fast storage with minimal boilerplate | Complex plugins needing multiple strongly-typed `BlockData` classes per block | Both are production-ready; pick based on complexity and team experience |

In short, `Cosmoverse/BlockData` is an excellent, highly flexible low-level building block.  
This library repackages the same core ideas into a **smaller, flatter API** that is easier to adopt quickly, without giving up LevelDB performance.

## License

LGPL-3.0-or-later
