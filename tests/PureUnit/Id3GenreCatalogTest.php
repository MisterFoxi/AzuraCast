<?php

declare(strict_types=1);

namespace PureUnit;

use App\Media\Id3GenreCatalog;
use PHPUnit\Framework\TestCase;

final class Id3GenreCatalogTest extends TestCase
{
    public function testContainsTheCompleteId3v1AndWinampCatalog(): void
    {
        self::assertCount(192, Id3GenreCatalog::GENRES);
        self::assertSame('Blues', Id3GenreCatalog::get(0));
        self::assertSame('Hard Rock', Id3GenreCatalog::get(79));
        self::assertSame('Folk', Id3GenreCatalog::get(80));
        self::assertSame('Anime', Id3GenreCatalog::get(145));
        self::assertSame('Jpop', Id3GenreCatalog::get(146));
        self::assertSame('Psybient', Id3GenreCatalog::get(191));
        self::assertNull(Id3GenreCatalog::get(255));
    }

    public function testNormalizesKnownGenresAndNumericId3Values(): void
    {
        self::assertSame('Jpop', Id3GenreCatalog::normalize(' J-Pop '));
        self::assertSame('Rock', Id3GenreCatalog::normalize('ROCK'));
        self::assertSame('Rock', Id3GenreCatalog::normalize('(17)'));
        self::assertSame('Rock', Id3GenreCatalog::normalize('(17) Rock'));
        self::assertSame('R&B', Id3GenreCatalog::normalize('Rhythm and Blues'));
    }

    public function testPreservesAUserDefinedGenre(): void
    {
        self::assertSame('Future Funk', Id3GenreCatalog::normalize('  Future   Funk  '));
        self::assertNull(Id3GenreCatalog::normalize(" \t "));
    }

    public function testCanonicalAndAliasKeysAreUniqueAndResolvable(): void
    {
        $canonicalByKey = [];
        foreach (Id3GenreCatalog::GENRES as $name) {
            $key = Id3GenreCatalog::key($name);
            self::assertArrayNotHasKey($key, $canonicalByKey, 'Duplicate canonical key: ' . $key);
            $canonicalByKey[$key] = $name;
        }

        $aliasKeys = [];
        foreach (Id3GenreCatalog::ALIASES as $alias => $canonicalName) {
            $key = Id3GenreCatalog::key($alias);
            self::assertArrayHasKey(Id3GenreCatalog::key($canonicalName), $canonicalByKey);
            self::assertArrayNotHasKey($key, $aliasKeys, 'Duplicate alias key: ' . $key);
            $aliasKeys[$key] = $canonicalName;
            self::assertSame($canonicalName, Id3GenreCatalog::normalize($alias));
        }
    }
}
