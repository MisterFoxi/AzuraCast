<?php

declare(strict_types=1);

namespace PureUnit;

use App\Entity\Enums\SmartBlockType;
use App\Entity\Station;
use App\Entity\StationPlaylist;
use App\Radio\SmartBlock\SmartBlockPlaybackPreparer;
use App\Radio\SmartBlock\SmartBlockSynchronizerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;
use RuntimeException;

final class SmartBlockPlaybackPreparerTest extends TestCase
{
    public function testDynamicSmartBlockIsSynchronizedOncePerQueueBuild(): void
    {
        $playlist = $this->createPlaylist();

        $synchronizer = $this->createMock(SmartBlockSynchronizerInterface::class);
        $synchronizer
            ->expects(self::exactly(2))
            ->method('synchronize')
            ->with($playlist)
            ->willReturn($this->unchangedResult());

        $preparer = new SmartBlockPlaybackPreparer($synchronizer, new NullLogger());

        self::assertTrue($preparer->prepare($playlist));
        self::assertTrue($preparer->prepare($playlist));

        $preparer->beginQueueBuild();

        self::assertTrue($preparer->prepare($playlist));
    }

    public function testStaticAndNormalPlaylistsAreNotAutomaticallySynchronized(): void
    {
        $normalPlaylist = $this->createPlaylist();
        $normalPlaylist->is_smart_block = false;

        $staticSmartBlock = $this->createPlaylist();
        $staticSmartBlock->smart_block_type = SmartBlockType::Static;

        $synchronizer = $this->createMock(SmartBlockSynchronizerInterface::class);
        $synchronizer->expects(self::never())->method('synchronize');

        $preparer = new SmartBlockPlaybackPreparer($synchronizer, new NullLogger());

        self::assertTrue($preparer->prepare($normalPlaylist));
        self::assertTrue($preparer->prepare($staticSmartBlock));
    }

    public function testSynchronizationFailureSkipsPlaylistAndIsCached(): void
    {
        $playlist = $this->createPlaylist();

        $synchronizer = $this->createMock(SmartBlockSynchronizerInterface::class);
        $synchronizer
            ->expects(self::once())
            ->method('synchronize')
            ->with($playlist)
            ->willThrowException(new RuntimeException('Selection failed.'));

        $preparer = new SmartBlockPlaybackPreparer($synchronizer, new NullLogger());

        self::assertFalse($preparer->prepare($playlist));
        self::assertFalse($preparer->prepare($playlist));
    }

    private function createPlaylist(): StationPlaylist
    {
        $playlist = new StationPlaylist(new Station());
        $playlist->name = 'Smart Block';
        $playlist->is_smart_block = true;
        $playlist->smart_block_type = SmartBlockType::Dynamic;
        (new ReflectionProperty($playlist, 'id'))->setValue($playlist, 10);

        return $playlist;
    }

    /** @return array{matched: int, added: int, removed: int, unchanged: int, changed: bool} */
    private function unchangedResult(): array
    {
        return [
            'matched' => 1,
            'added' => 0,
            'removed' => 0,
            'unchanged' => 1,
            'changed' => false,
        ];
    }
}
