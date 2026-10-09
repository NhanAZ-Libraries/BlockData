<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use NhanAZ\BlockData\BlockDataWorld;
use pocketmine\world\World;

$root = sys_get_temp_dir() . '/blockdata-test-' . bin2hex(random_bytes(8));
$world = (new ReflectionClass(World::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(World::class, 'folderName'))->setValue($world, 'test-world');

try{
	$storage = new BlockDataWorld($root, $world);
	$storage->set(1, 64, 2, ['owner' => 'alice']);
	$storage->unloadChunk(0, 0);
	if($storage->get(1, 64, 2) !== ['owner' => 'alice']){
		throw new RuntimeException('Chunk unload did not persist block data');
	}

	$storage->set(3, 64, 4, INF);
	try{
		$storage->unloadChunk(0, 0);
		throw new RuntimeException('Invalid JSON unexpectedly flushed');
	}catch(JsonException){
	}
	$dirty = (new ReflectionProperty(BlockDataWorld::class, 'dirty'))->getValue($storage);
	if(!array_key_exists(World::blockHash(3, 64, 4), $dirty)){
		throw new RuntimeException('Failed flush discarded pending data');
	}

	$storage->set(3, 64, 4, ['owner' => 'bob']);
	$storage->save();
	$storage->close();

	$reopened = new BlockDataWorld($root, $world);
	if($reopened->get(1, 64, 2) !== ['owner' => 'alice'] || $reopened->get(3, 64, 4) !== ['owner' => 'bob']){
		throw new RuntimeException('Saved block data did not survive reopening LevelDB');
	}
	$reopened->remove(1, 64, 2);
	$reopened->save();
	$reopened->close();

	$reopened = new BlockDataWorld($root, $world);
	if($reopened->get(1, 64, 2) !== null){
		throw new RuntimeException('Removed block data survived reopening LevelDB');
	}
	$reopened->close();
	echo "BlockData storage checks passed.\n";
}finally{
	if(is_dir($root)){
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach($files as $file){
			$file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
		}
		rmdir($root);
	}
}
