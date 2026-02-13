<?php

declare(strict_types=1);

namespace NhanAZ\BlockData;

use InvalidArgumentException;
use JsonException;
use pocketmine\block\Block;
use pocketmine\plugin\PluginBase;
use pocketmine\world\World;
use RuntimeException;
use Symfony\Component\Filesystem\Path;

/**
 * A high-performance, developer-friendly library for storing custom data on blocks.
 *
 * Usage:
 *   $blockData = BlockData::create($this);
 *   $blockData->set($block, ["owner" => "Steve", "level" => 3]);
 *   $data = $blockData->get($block);
 *   $blockData->remove($block);
 */
final class BlockData{

	/** @var array<int, BlockDataWorld> */
	private array $worlds = [];
	/** @var array<int, BlockDataChunkListener> */
	private array $chunk_listeners = [];

	private function __construct(
		private string $dataPath,
		private bool $autoCleanup
	){}

	/**
	 * Creates a new BlockData instance for your plugin.
	 * Call this once in your plugin's onEnable() method.
	 *
	 * @param PluginBase  $plugin      Your plugin instance
	 * @param bool        $autoCleanup If true, block data is automatically removed
	 *                                 when blocks are destroyed (break, explode, burn, decay)
	 * @param string|null $dataPath    Base directory for storing per-world LevelDB databases.
	 *                                 If null, defaults to "<plugin data folder>/blockdata".
	 *
	 * @throws \RuntimeException If LevelDB extension is missing
	 *                            or data directory cannot be created
	 */
	public static function create(PluginBase $plugin, bool $autoCleanup = false, ?string $dataPath = null) : self{
		if(!extension_loaded("leveldb")){
			throw new RuntimeException(
				"BlockData requires the LevelDB PHP extension. " .
				"This extension is bundled with PocketMine-MP by default."
			);
		}

		$instance = new self($dataPath ?? Path::join($plugin->getDataFolder(), "blockdata"), $autoCleanup);
		$server = $plugin->getServer();

		$server->getPluginManager()->registerEvents(
			new BlockDataListener($instance, $plugin),
			$plugin
		);

		foreach($server->getWorldManager()->getWorlds() as $world){
			$instance->loadWorld($world);
		}

		return $instance;
	}

	/**
	 * Stores data for a block.
	 * Accepts any JSON-serializable value: string, int, float, bool, or array.
	 *
	 * @param Block $block The block to store data for
	 * @param mixed $data  The data to store (must be JSON-serializable, cannot be null)
	 *
	 * @throws InvalidArgumentException if $data is null or not JSON-serializable
	 */
	public function set(Block $block, mixed $data) : void{
		$pos = $block->getPosition();
		$this->setAt($pos->getWorld(), $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ(), $data);
	}

	/**
	 * Gets the data stored for a block.
	 *
	 * @return mixed The stored data, or null if no data exists
	 */
	public function get(Block $block) : mixed{
		$pos = $block->getPosition();
		return $this->getAt($pos->getWorld(), $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
	}

	/**
	 * Checks if a block has data stored.
	 */
	public function has(Block $block) : bool{
		return $this->get($block) !== null;
	}

	/**
	 * Removes the data stored for a block.
	 */
	public function remove(Block $block) : void{
		$pos = $block->getPosition();
		$this->removeAt($pos->getWorld(), $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
	}

	/**
	 * Stores data at specific world coordinates.
	 *
	 * @throws InvalidArgumentException if $data is null or not JSON-serializable
	 */
	public function setAt(World $world, int $x, int $y, int $z, mixed $data) : void{
		if($data === null){
			throw new InvalidArgumentException("Data cannot be null. Use remove() or removeAt() to delete block data.");
		}
		try{
			json_encode($data, JSON_THROW_ON_ERROR);
		}catch(JsonException $e){
			throw new InvalidArgumentException("Data must be JSON-serializable: " . $e->getMessage(), 0, $e);
		}
		$this->getWorld($world)->set($x, $y, $z, $data);
	}

	/**
	 * Gets data at specific world coordinates.
	 *
	 * @return mixed The stored data, or null if no data exists
	 */
	public function getAt(World $world, int $x, int $y, int $z) : mixed{
		return $this->getWorld($world)->get($x, $y, $z);
	}

	/**
	 * Checks if data exists at specific world coordinates.
	 */
	public function hasAt(World $world, int $x, int $y, int $z) : bool{
		return $this->getAt($world, $x, $y, $z) !== null;
	}

	/**
	 * Removes data at specific world coordinates.
	 */
	public function removeAt(World $world, int $x, int $y, int $z) : void{
		$this->getWorld($world)->remove($x, $y, $z);
	}

	// ── Internal methods (used by BlockDataListener) ──────────────────

	/** @internal */
	public function loadWorld(World $world) : void{
		if(!isset($this->worlds[$world->getId()])){
			$this->worlds[$world->getId()] = new BlockDataWorld($this->dataPath, $world);
			if($this->autoCleanup){
				$listener = ($this->chunk_listeners[$world->getId()] ??= new BlockDataChunkListener($this, $world));
				foreach($world->getLoadedChunks() as $hash => $_){
					World::getXZ($hash, $x, $z);
					$world->registerChunkListener($listener, $x, $z);
				}
			}
		}
	}

	/** @internal */
	public function unloadWorld(World $world) : void{
		$id = $world->getId();
		if(isset($this->worlds[$id])){
			if($this->autoCleanup && isset($this->chunk_listeners[$id])){
				$world->unregisterChunkListenerFromAll($this->chunk_listeners[$id]);
				unset($this->chunk_listeners[$id]);
			}
			$this->worlds[$id]->close();
			unset($this->worlds[$id]);
		}
	}

	/** @internal */
	public function saveWorld(World $world) : void{
		$id = $world->getId();
		if(isset($this->worlds[$id])){
			$this->worlds[$id]->save();
		}
	}

	/** @internal */
	public function onChunkLoad(World $world, int $chunkX, int $chunkZ) : void{
		$id = $world->getId();
		if(isset($this->worlds[$id])){
			if($this->autoCleanup && isset($this->chunk_listeners[$id])){
				$world->registerChunkListener($this->chunk_listeners[$id], $chunkX, $chunkZ);
			}
			$this->worlds[$id]->loadChunk($chunkX, $chunkZ);
		}
	}

	/** @internal */
	public function onChunkUnload(World $world, int $chunkX, int $chunkZ) : void{
		$id = $world->getId();
		if(isset($this->worlds[$id])){
			if($this->autoCleanup && isset($this->chunk_listeners[$id])){
				$world->unregisterChunkListener($this->chunk_listeners[$id], $chunkX, $chunkZ);
			}
			$this->worlds[$id]->unloadChunk($chunkX, $chunkZ);
		}
	}

	/** @internal */
	public function closeAll() : void{
		foreach($this->worlds as $id => $world){
			$world->close();
			unset($this->worlds[$id]);
		}
	}

	private function getWorld(World $world) : BlockDataWorld{
		return $this->worlds[$world->getId()]
			?? throw new RuntimeException("World '{$world->getFolderName()}' is not loaded in BlockData");
	}
}
