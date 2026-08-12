<?php

declare(strict_types=1);

namespace PureUnit;

use App\Entity\CustomField;
use App\Entity\Enums\SmartBlockCriteriaComparison;
use App\Entity\Enums\SmartBlockCriteriaField;
use App\Entity\Enums\SmartBlockLimitType;
use App\Entity\Enums\SmartBlockMatchType;
use App\Entity\Enums\SmartBlockType;
use App\Entity\Enums\StorageLocationAdapters;
use App\Entity\Enums\StorageLocationTypes;
use App\Entity\Repository\StationPlaylistSmartBlockCriteriaRepository;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationPlaylist;
use App\Entity\StationPlaylistSmartBlockCriteria;
use App\Entity\StorageLocation;
use App\Radio\SmartBlock\SmartBlockCriteriaExpressionBuilder;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class SmartBlockCriteriaExpressionBuilderTest extends TestCase
{
    public function testPlaylistDefaultsAndLimitNormalization(): void
    {
        $playlist = new StationPlaylist(new Station());

        self::assertFalse($playlist->is_smart_block);
        self::assertSame(SmartBlockMatchType::All, $playlist->smart_block_match_type);
        self::assertSame(SmartBlockLimitType::Tracks, $playlist->smart_block_limit_type);
        self::assertSame(SmartBlockType::Dynamic, $playlist->smart_block_type);

        $playlist->smart_block_limit = '25';
        self::assertSame(25, $playlist->smart_block_limit);

        $playlist->smart_block_limit = 0;
        self::assertNull($playlist->smart_block_limit);
    }

    public function testCombinesTextAndNumericCriteriaWithAll(): void
    {
        $playlist = new StationPlaylist(new Station());
        $this->addCriterion(
            $playlist,
            SmartBlockCriteriaField::Genre,
            SmartBlockCriteriaComparison::Contains,
            'Rock'
        );
        $this->addCriterion(
            $playlist,
            SmartBlockCriteriaField::Duration,
            SmartBlockCriteriaComparison::Between,
            '120',
            '300'
        );

        $expression = (new SmartBlockCriteriaExpressionBuilder())->build(
            $playlist->smart_block_criteria,
            SmartBlockMatchType::All
        );

        self::assertNotNull($expression);
        self::assertStringContainsString('LOWER(sm.genre) LIKE :val0', $expression['where']);
        self::assertStringContainsString(' AND ', $expression['where']);
        self::assertStringContainsString('sm.length BETWEEN :val1 AND :val1b', $expression['where']);
        self::assertSame('%rock%', $expression['parameters']['val0']);
        self::assertSame(120.0, $expression['parameters']['val1']);
        self::assertSame(300.0, $expression['parameters']['val1b']);
    }

    public function testCombinesCriteriaWithAny(): void
    {
        $playlist = new StationPlaylist(new Station());
        $this->addCriterion(
            $playlist,
            SmartBlockCriteriaField::Artist,
            SmartBlockCriteriaComparison::Is,
            'Artist A'
        );
        $this->addCriterion(
            $playlist,
            SmartBlockCriteriaField::Album,
            SmartBlockCriteriaComparison::Is,
            'Album B'
        );

        $expression = (new SmartBlockCriteriaExpressionBuilder())->build(
            $playlist->smart_block_criteria,
            SmartBlockMatchType::Any
        );

        self::assertNotNull($expression);
        self::assertStringContainsString(' OR ', $expression['where']);
        self::assertStringNotContainsString(' AND ', $expression['where']);
    }

    public function testCategoryUsesTheExactMediaCategoryId(): void
    {
        $playlist = new StationPlaylist(new Station());
        $this->addCriterion(
            $playlist,
            SmartBlockCriteriaField::Category,
            SmartBlockCriteriaComparison::Is,
            '42'
        );

        $expression = (new SmartBlockCriteriaExpressionBuilder())->build(
            $playlist->smart_block_criteria,
            SmartBlockMatchType::All
        );

        self::assertNotNull($expression);
        self::assertStringContainsString('sm.category_id = :val0', $expression['where']);
        self::assertSame(42, $expression['parameters']['val0']);
    }

    public function testCustomFieldNotContainsUsesNotExistsAndLike(): void
    {
        $playlist = new StationPlaylist(new Station());
        $criterion = $this->addCriterion(
            $playlist,
            SmartBlockCriteriaField::CustomField,
            SmartBlockCriteriaComparison::NotContains,
            'calm'
        );
        $customField = new CustomField();
        $customField->name = 'Mood';
        $criterion->custom_field = $customField;

        $expression = (new SmartBlockCriteriaExpressionBuilder())->build(
            $playlist->smart_block_criteria,
            SmartBlockMatchType::All
        );

        self::assertNotNull($expression);
        self::assertStringContainsString('NOT EXISTS', $expression['where']);
        self::assertStringContainsString('LOWER(smcf0.value) LIKE :val0', $expression['where']);
        self::assertSame('%calm%', $expression['parameters']['val0']);
        self::assertSame($customField, $expression['parameters']['field0']);
    }

    public function testIncompleteCriteriaAreIgnored(): void
    {
        $playlist = new StationPlaylist(new Station());
        $this->addCriterion(
            $playlist,
            SmartBlockCriteriaField::CustomField,
            SmartBlockCriteriaComparison::Is,
            '120'
        );
        $this->addCriterion(
            $playlist,
            SmartBlockCriteriaField::Duration,
            SmartBlockCriteriaComparison::Between,
            '60'
        );

        self::assertNull(
            (new SmartBlockCriteriaExpressionBuilder())->build(
                $playlist->smart_block_criteria,
                SmartBlockMatchType::All
            )
        );
    }

    public function testLastPlayedBuildsStableThreshold(): void
    {
        $playlist = new StationPlaylist(new Station());
        $this->addCriterion(
            $playlist,
            SmartBlockCriteriaField::LastPlayed,
            SmartBlockCriteriaComparison::GreaterThan,
            '7'
        );
        $now = new DateTimeImmutable('2026-08-12 12:00:00 UTC');

        $expression = (new SmartBlockCriteriaExpressionBuilder())->build(
            $playlist->smart_block_criteria,
            SmartBlockMatchType::All,
            $now
        );

        self::assertNotNull($expression);
        self::assertStringContainsString('IS NULL OR', $expression['where']);
        self::assertEquals(new DateTimeImmutable('2026-08-05 12:00:00 UTC'), $expression['parameters']['val0']);
    }

    public function testDurationLimitNeverExceedsMaximum(): void
    {
        $storage = new StorageLocation(StorageLocationTypes::StationMedia, StorageLocationAdapters::Local);
        $tooLong = new StationMedia($storage, 'too-long.mp3');
        $tooLong->length = 130;
        $first = new StationMedia($storage, 'first.mp3');
        $first->length = 70;
        $second = new StationMedia($storage, 'second.mp3');
        $second->length = 50;

        $repository = new StationPlaylistSmartBlockCriteriaRepository(
            new SmartBlockCriteriaExpressionBuilder()
        );

        self::assertSame(
            [$first, $second],
            $repository->limitByDuration([$tooLong, $first, $second], 120)
        );
    }

    public function testCloneDoesNotKeepCriteria(): void
    {
        $playlist = new StationPlaylist(new Station());
        $this->addCriterion(
            $playlist,
            SmartBlockCriteriaField::Genre,
            SmartBlockCriteriaComparison::Is,
            'Rock'
        );

        $clone = clone $playlist;

        self::assertCount(1, $playlist->smart_block_criteria);
        self::assertCount(0, $clone->smart_block_criteria);
    }

    private function addCriterion(
        StationPlaylist $playlist,
        SmartBlockCriteriaField $field,
        SmartBlockCriteriaComparison $comparison,
        ?string $value,
        ?string $value2 = null
    ): StationPlaylistSmartBlockCriteria {
        $criterion = new StationPlaylistSmartBlockCriteria($playlist);
        $criterion->field = $field;
        $criterion->comparison = $comparison;
        $criterion->value = $value;
        $criterion->value2 = $value2;
        $playlist->smart_block_criteria->add($criterion);

        return $criterion;
    }
}
