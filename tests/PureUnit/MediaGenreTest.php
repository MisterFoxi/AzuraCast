<?php

declare(strict_types=1);

namespace PureUnit;

use App\Entity\MediaGenre;
use App\Entity\MediaGenreAlias;
use PHPUnit\Framework\TestCase;

final class MediaGenreTest extends TestCase
{
    public function testCreatesCanonicalAndCustomGenres(): void
    {
        $canonical = new MediaGenre('Jpop', 'jpop');
        $canonical->id3_id = 146;

        self::assertSame('Jpop', $canonical->name);
        self::assertSame('jpop', $canonical->normalized_name);
        self::assertSame(146, $canonical->id3_id);
        self::assertTrue($canonical->is_active);
        self::assertFalse($canonical->is_custom);
        self::assertSame('Jpop', (string)$canonical);

        $custom = new MediaGenre('Future Funk', 'futurefunk');
        $custom->is_custom = true;

        self::assertNull($custom->id3_id);
        self::assertTrue($custom->is_custom);
    }

    public function testCreatesAnAliasForACanonicalGenre(): void
    {
        $canonical = new MediaGenre('R&B', 'rb');
        $alias = new MediaGenreAlias(
            $canonical,
            'Rhythm and Blues',
            'rhythmandblues'
        );

        self::assertSame($canonical, $alias->genre);
        self::assertSame('Rhythm and Blues', $alias->alias);
        self::assertSame('rhythmandblues', $alias->normalized_alias);
    }
}
