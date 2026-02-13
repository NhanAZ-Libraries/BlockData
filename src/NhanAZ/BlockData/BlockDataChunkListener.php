<?php
/*
 * Copyright (C) 2020 kostamax27
 */

declare(strict_types=1);

namespace NhanAZ\BlockData;

use pocketmine\math\Vector3;
use pocketmine\world\ChunkListener;
use pocketmine\world\ChunkListenerNoOpTrait;
use pocketmine\world\format\Chunk;
use pocketmine\world\World;

class BlockDataChunkListener implements ChunkListener{
	use ChunkListenerNoOpTrait;

	public function __construct(
		private readonly BlockData $blockData,
		private readonly World $world,
	){}

	public function onChunkChanged(int $chunkX, int $chunkZ, Chunk $chunk) : void{
		//TODO: ...
	}

	public function onBlockChanged(Vector3 $block) : void{
		//TODO: strict block modification checking???
		$this->blockData->removeAt(
			$this->world,
			$block->getFloorX(),
			$block->getFloorY(),
			$block->getFloorZ(),
		);
	}
}
