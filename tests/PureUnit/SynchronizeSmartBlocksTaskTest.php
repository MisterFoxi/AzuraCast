<?php

declare(strict_types=1);

namespace PureUnit;

use App\Entity\Enums\PlaylistSources;
use App\Entity\Enums\SmartBlockType;
use App\Entity\Station;
use App\Entity\StationPlaylist;
use App\Radio\SmartBlock\SmartBlockSynchronizerInterface;
use App\Sync\Task\ScheduledTaskInterface;
use App\Sync\Task\SynchronizeSmartBlocksTask;
use Doctrine\Common\Collections\ArrayCollection;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class SynchronizeSmartBlocksTaskTest extends TestCase
{
    public function testRunsEveryFiveMinutesAndOnlySynchronizesDynamicSmartBlocks(): void
    {
        self::assertSame(
            ScheduledTaskInterface::SCHEDULE_EVERY_FIVE_MINUTES,
            SynchronizeSmartBlocksTask::getSchedulePattern()
        );

        $station = new Station();
        $station->name = 'Test Station';

        $dynamic = $this->createSmartBlock($station, SmartBlockType::Dynamic);
        $static = $this->createSmartBlock($station, SmartBlockType::Static);
        $ordinary = new StationPlaylist($station);
        $ordinary->name = 'Ordinary';
        $ordinary->is_smart_block = false;

        (new ReflectionProperty($station, 'playlists'))->setValue(
            $station,
            new ArrayCollection([$dynamic, $static, $ordinary])
        );

        $synchronizer = $this->createMock(SmartBlockSynchronizerInterface::class);
        $synchronizer
            ->expects(self::once())
            ->method('synchronize')
            ->with($dynamic)
            ->willReturn([
                'matched' => 0,
                'added' => 0,
                'removed' => 0,
                'unchanged' => 0,
                'changed' => false,
            ]);

        $task = new SynchronizeSmartBlocksTask($synchronizer);
        $task->setLogger($this->createMock(Logger::class));
        $task->synchronizeStation($station);
    }

    private function createSmartBlock(Station $station, SmartBlockType $type): StationPlaylist
    {
        $playlist = new StationPlaylist($station);
        $playlist->name = $type->value;
        $playlist->source = PlaylistSources::Songs;
        $playlist->is_smart_block = true;
        $playlist->smart_block_type = $type;

        return $playlist;
    }
}
