<?php

declare(strict_types=1);

namespace PureUnit;

use App\Entity\Enums\SmartBlockSort;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SmartBlockSortTest extends TestCase
{
    /** @return iterable<string, array{SmartBlockSort, list<array{expression: string, direction: string|null}>}> */
    public static function sortProvider(): iterable
    {
        yield 'random' => [SmartBlockSort::Random, [['expression' => 'RAND()', 'direction' => null]]];
        yield 'newest' => [SmartBlockSort::Newest, [
            ['expression' => 'sm.uploaded_at', 'direction' => 'DESC'],
            ['expression' => 'sm.id', 'direction' => 'DESC'],
        ]];
        yield 'oldest' => [SmartBlockSort::Oldest, [
            ['expression' => 'sm.uploaded_at', 'direction' => 'ASC'],
            ['expression' => 'sm.id', 'direction' => 'ASC'],
        ]];
        yield 'title' => [SmartBlockSort::Title, [
            ['expression' => 'LOWER(sm.title)', 'direction' => 'ASC'],
            ['expression' => 'sm.id', 'direction' => 'ASC'],
        ]];
        yield 'artist' => [SmartBlockSort::Artist, [
            ['expression' => 'LOWER(sm.artist)', 'direction' => 'ASC'],
            ['expression' => 'LOWER(sm.title)', 'direction' => 'ASC'],
            ['expression' => 'sm.id', 'direction' => 'ASC'],
        ]];
    }

    #[DataProvider('sortProvider')]
    public function testSortOrder(SmartBlockSort $sort, array $expected): void
    {
        self::assertSame($expected, $sort->getOrderBy());
    }
}
