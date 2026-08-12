<?php

declare(strict_types=1);

namespace PureUnit;

use App\Entity\Enums\SmartBlockCriteriaComparison;
use App\Entity\Enums\SmartBlockCriteriaField;
use App\Entity\Station;
use App\Entity\StationMediaCategory;
use App\Entity\StationPlaylist;
use App\Exception\ValidationException;
use App\Radio\SmartBlock\SmartBlockCriteriaPayloadParser;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class SmartBlockCriteriaPayloadParserTest extends TestCase
{
    public function testCategoryCriterionUsesCategoryFromSameStation(): void
    {
        $station = new Station();
        $playlist = $this->makePlaylist($station);
        $category = new StationMediaCategory($station);
        $this->setId($category, 42);

        $parser = $this->makeParser([42 => $category]);
        $rows = $parser->parse($playlist, [[
            'field' => 'category',
            'comparison' => 'is',
            'value' => 42,
        ]]);

        self::assertCount(1, $rows);
        self::assertSame(SmartBlockCriteriaField::Category, $rows[0]->field);
        self::assertSame(SmartBlockCriteriaComparison::Is, $rows[0]->comparison);
        self::assertSame('42', $rows[0]->value);
    }

    public function testCategoryFromAnotherStationIsRejected(): void
    {
        $station = new Station();
        $foreignCategory = new StationMediaCategory(new Station());
        $this->setId($foreignCategory, 42);

        $parser = $this->makeParser([42 => $foreignCategory]);

        $this->expectValidation('category is invalid for this station.');
        $parser->parse($this->makePlaylist($station), [[
            'field' => 'category',
            'comparison' => 'is',
            'value' => '42',
        ]]);
    }

    public function testCategoryRejectsTextComparison(): void
    {
        $parser = $this->makeParser();

        $this->expectValidation('comparison is not supported for this field.');
        $parser->parse($this->makePlaylist(new Station()), [[
            'field' => 'category',
            'comparison' => 'contains',
            'value' => '42',
        ]]);
    }

    public function testNumericFieldRejectsNonNumericValue(): void
    {
        $parser = $this->makeParser();

        $this->expectValidation('value must be numeric.');
        $parser->parse($this->makePlaylist(new Station()), [[
            'field' => 'duration',
            'comparison' => 'greater_than',
            'value' => 'long',
        ]]);
    }

    public function testExactDuplicatesAreRemovedAndWeightsRemainContiguous(): void
    {
        $parser = $this->makeParser();
        $rows = $parser->parse($this->makePlaylist(new Station()), [
            [
                'field' => 'genre',
                'comparison' => 'is',
                'value' => 'Rock',
            ],
            [
                'field' => 'genre',
                'comparison' => 'is',
                'value' => 'Rock',
            ],
            [
                'field' => 'artist',
                'comparison' => 'contains',
                'value' => 'Fox',
            ],
        ]);

        self::assertCount(2, $rows);
        self::assertSame([0, 1], array_map(static fn($row): int => $row->weight, $rows));
    }

    public function testCriteriaMustBeAList(): void
    {
        $parser = $this->makeParser();

        $this->expectValidation('criteria must be a list.');
        $parser->parse($this->makePlaylist(new Station()), ['field' => 'genre']);
    }

    /** @param array<int, StationMediaCategory> $categories */
    private function makeParser(array $categories = []): SmartBlockCriteriaPayloadParser
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(
            static fn(string $class, int|string $id): ?StationMediaCategory =>
                StationMediaCategory::class === $class ? ($categories[(int)$id] ?? null) : null
        );

        return new SmartBlockCriteriaPayloadParser($em);
    }

    private function makePlaylist(Station $station): StationPlaylist
    {
        $playlist = new StationPlaylist($station);
        $playlist->name = 'Smart Block';
        return $playlist;
    }

    private function expectValidation(string $message): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessage($message);
    }

    private function setId(object $entity, int $id): void
    {
        (new ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }
}
