<?php

declare(strict_types=1);

namespace NhanAZ\BlockData;

use JsonException;
use LevelDB;
use LevelDBWriteBatch;
use pocketmine\world\World;
use Symfony\Component\Filesystem\Path;

/**
 * @internal Manages block data storage and caching for a single world.
 *
 * Uses LevelDB with Snappy compression for high-performance key-value storage.
 * Implements lazy loading (read from DB only on first access) and
 * write-behind batching (queue writes, flush on save/chunk unload).
 */
final class BlockDataWorld{

	private LevelDB $db;

	/**
	 * In-memory cache: blockHash => stored data.
	 * Only non-null values are cached; cache entries are evicted on chunk unload.
	 * @var array<int, mixed>
	 */
	private array $cache = [];

	/**
	 * Pending writes: blockHash => data to save (null means delete).
	 * Flushed to LevelDB on save() or chunk unload.
	 * @var array<int, mixed>
	 */
	private array $dirty = [];

	/**
	 * Tracks which block hashes belong to which chunk, for cache eviction.
	 * chunkHash => [blockHash => true, ...]
	 * @var array<int, array<int, true>>
	 */
	private array $chunkBlocks = [];

	public function __construct(string $dataPath, private World $world){
		$worldPath = Path::join($dataPath, $this->world->getFolderName());
		if(!mkdir($worldPath, 0777, true) && !is_dir($worldPath)){
			throw new \RuntimeException("Directory \"$worldPath\" was not created");
		}

		$this->db = new LevelDB($worldPath, [
			"compression" => LEVELDB_SNAPPY_COMPRESSION,
			"block_size" => 64 * 1024,
		]);
	}

	/**
	 * Gets block data at the given coordinates.
	 * First access reads from LevelDB; subsequent reads use the in-memory cache.
	 *
	 * @return mixed The stored data, or null if no data exists
	 */
	public function get(int $x, int $y, int $z) : mixed{
		$hash = World::blockHash($x, $y, $z);

		if(array_key_exists($hash, $this->cache)){
			return $this->cache[$hash];
		}

		// Cache miss → read from LevelDB
		$raw = $this->db->get("b" . $hash);
		if($raw !== false){
			try{
				$data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
			}catch(JsonException){
				$data = null;
			}
		}else{
			$data = null;
		}

		// Only cache non-null values to avoid unbounded "dead cache" growth
		if($data !== null){
			$this->cache[$hash] = $data;
			$this->trackBlock($x, $z, $hash);
		}

		return $data;
	}

	/**
	 * Stores block data at the given coordinates.
	 * Data is cached immediately and queued for batch write to LevelDB.
	 */
	public function set(int $x, int $y, int $z, mixed $data) : void{
		$hash = World::blockHash($x, $y, $z);
		$this->cache[$hash] = $data;
		$this->dirty[$hash] = $data;
		$this->trackBlock($x, $z, $hash);
	}

	/**
	 * Removes block data at the given coordinates.
	 * The deletion is cached immediately and queued for batch write.
	 */
	public function remove(int $x, int $y, int $z) : void{
		$hash = World::blockHash($x, $y, $z);
		$this->cache[$hash] = null;
		$this->dirty[$hash] = null;
		$this->trackBlock($x, $z, $hash);
	}

	/**
	 * Registers a chunk as loaded (creates tracking entry for cache eviction).
	 */
	public function loadChunk(int $chunkX, int $chunkZ) : void{
		$chunkHash = World::chunkHash($chunkX, $chunkZ);
		if(!isset($this->chunkBlocks[$chunkHash])){
			$this->chunkBlocks[$chunkHash] = [];
		}
	}

	/**
	 * Unloads a chunk: flushes any dirty data for the chunk, then evicts all
	 * cached entries for blocks in that chunk to free memory.
	 */
	public function unloadChunk(int $chunkX, int $chunkZ) : void{
		$chunkHash = World::chunkHash($chunkX, $chunkZ);

		try{
			$this->flushChunk($chunkHash);
		}finally{
			// Always evict cache, even if flush fails
			if(isset($this->chunkBlocks[$chunkHash])){
				foreach($this->chunkBlocks[$chunkHash] as $blockHash => $_){
					unset($this->cache[$blockHash]);
					unset($this->dirty[$blockHash]);
				}
				unset($this->chunkBlocks[$chunkHash]);
			}
		}
	}

	/**
	 * Flushes ALL pending writes to LevelDB using a single batch operation.
	 */
	public function save() : void{
		if(count($this->dirty) === 0){
			return;
		}

		$batch = new LevelDBWriteBatch();
		foreach($this->dirty as $hash => $data){
			$key = "b" . $hash;
			if($data !== null){
				$batch->put($key, json_encode($data, JSON_THROW_ON_ERROR));
			}else{
				$batch->delete($key);
			}
		}
		$this->db->write($batch);
		$this->dirty = [];
	}

	/**
	 * Saves all pending data and releases the LevelDB connection.
	 */
	public function close() : void{
		$this->save();
		$this->cache = [];
		$this->dirty = [];
		$this->chunkBlocks = [];
		unset($this->db);
	}

	// ── Private helpers ──────────────────────────────────────────────

	/**
	 * Associates a block hash with its chunk for later cache eviction.
	 */
	private function trackBlock(int $x, int $z, int $blockHash) : void{
		$chunkHash = World::chunkHash($x >> 4, $z >> 4);
		$this->chunkBlocks[$chunkHash][$blockHash] = true;
	}

	/**
	 * Flushes only the dirty entries that belong to a specific chunk.
	 */
	private function flushChunk(int $chunkHash) : void{
		if(!isset($this->chunkBlocks[$chunkHash]) || count($this->dirty) === 0){
			return;
		}

		$batch = new LevelDBWriteBatch();
		$hasEntries = false;

		foreach($this->chunkBlocks[$chunkHash] as $blockHash => $_){
			if(array_key_exists($blockHash, $this->dirty)){
				$key = "b" . $blockHash;
				$data = $this->dirty[$blockHash];
				if($data !== null){
					$batch->put($key, json_encode($data, JSON_THROW_ON_ERROR));
				}else{
					$batch->delete($key);
				}
				$hasEntries = true;
			}
		}

		if($hasEntries){
			$this->db->write($batch);
		}
	}
}
