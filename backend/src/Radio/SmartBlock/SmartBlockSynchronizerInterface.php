<?php

declare(strict_types=1);

namespace App\Radio\SmartBlock;

use App\Entity\StationPlaylist;

interface SmartBlockSynchronizerInterface
{
    /**
     * @return array{matched: int, added: int, removed: int, unchanged: int, changed: bool}
     */
    public function synchronize(StationPlaylist $playlist): array;
}
