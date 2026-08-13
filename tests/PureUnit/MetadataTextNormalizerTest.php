<?php

declare(strict_types=1);

namespace PureUnit;

use App\Media\MetadataTextNormalizer;
use PHPUnit\Framework\TestCase;

final class MetadataTextNormalizerTest extends TestCase
{
    public function testCleansTextWithoutChangingArtisticCase(): void
    {
        self::assertSame('AC/DC', MetadataTextNormalizer::normalize(" \tAC/DC\n"));
        self::assertSame('deadmau5', MetadataTextNormalizer::normalize('deadmau5'));
        self::assertSame('JAY-Z', MetadataTextNormalizer::normalize('JAY-Z'));
    }

    public function testNormalizesUnicodeWhitespaceQuotesAndControlCharacters(): void
    {
        self::assertSame(
            'Artist Name - \'Title\'',
            MetadataTextNormalizer::normalize("Artist\u{00A0}\u{00A0}Name\u{0000} – ‘Title’")
        );
    }

    public function testReturnsNullForAnEmptyValue(): void
    {
        self::assertNull(MetadataTextNormalizer::normalize(null));
        self::assertNull(MetadataTextNormalizer::normalize(" \t\n"));
    }
}
