<?php

declare(strict_types=1);

namespace PureUnit;

use App\Media\MediaGenreOptions;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class MediaGenreOptionsTest extends TestCase
{
    public function testReturnsTypedActiveGenreOptions(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::stringContains('sm.storage_location_id = :storage_location_id'),
                ['storage_location_id' => 42]
            )
            ->willReturn([
                ['id' => '147', 'id3_id' => '146', 'name' => 'Jpop', 'is_custom' => '0'],
                ['id' => '200', 'id3_id' => null, 'name' => 'Future Funk', 'is_custom' => '1'],
            ]);

        self::assertSame(
            [
                ['id' => 147, 'id3_id' => 146, 'name' => 'Jpop', 'is_custom' => false],
                ['id' => 200, 'id3_id' => null, 'name' => 'Future Funk', 'is_custom' => true],
            ],
            (new MediaGenreOptions($connection))->getActive(42)
        );
    }
}
