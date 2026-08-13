<?php

declare(strict_types=1);

namespace PureUnit;

use App\Entity\Enums\StorageLocationAdapters;
use App\Entity\Enums\StorageLocationTypes;
use App\Entity\StationMedia;
use App\Entity\StorageLocation;
use PHPUnit\Framework\TestCase;

final class StationMediaMetadataNormalizationTest extends TestCase
{
    public function testNormalizesMediaFieldsAndPreservesChangedSources(): void
    {
        $media = $this->createMedia();
        $media->artist = "  AC/DC\t";
        $media->title = "A  Song\u{2014}Live";
        $media->genre = 'J-Pop';

        $media->normalizeMetadataFields();
        $media->updateMetaFields();

        self::assertSame('AC/DC', $media->artist);
        self::assertSame('A Song-Live', $media->title);
        self::assertSame('Jpop', $media->genre);
        self::assertSame("  AC/DC\t", $media->original_artist);
        self::assertSame("A  Song\u{2014}Live", $media->original_title);
        self::assertSame('J-Pop', $media->original_genre);
        self::assertSame('AC/DC - A Song-Live', $media->text);
    }

    public function testDoesNotOverwriteTheFirstOriginalValue(): void
    {
        $media = $this->createMedia();
        $media->original_title = 'First  Source';
        $media->title = 'Second  Source';

        $media->normalizeMetadataFields();

        self::assertSame('Second Source', $media->title);
        self::assertSame('First  Source', $media->original_title);
    }

    private function createMedia(): StationMedia
    {
        $storage = new StorageLocation(
            StorageLocationTypes::StationMedia,
            StorageLocationAdapters::Local
        );

        return new StationMedia($storage, 'song.mp3');
    }
}
