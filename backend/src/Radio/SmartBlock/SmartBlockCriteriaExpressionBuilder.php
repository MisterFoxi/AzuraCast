<?php

declare(strict_types=1);

namespace App\Radio\SmartBlock;

use App\Entity\Enums\SmartBlockCriteriaComparison;
use App\Entity\Enums\SmartBlockCriteriaField;
use App\Entity\Enums\SmartBlockMatchType;
use App\Entity\SongHistory;
use App\Entity\StationMediaCustomField;
use App\Entity\StationPlaylistSmartBlockCriteria;
use DateTimeImmutable;

final class SmartBlockCriteriaExpressionBuilder
{
    /**
     * @param iterable<StationPlaylistSmartBlockCriteria> $criteria
     * @return array{where: string, parameters: array<string, mixed>}|null
     */
    public function build(
        iterable $criteria,
        SmartBlockMatchType $matchType,
        ?DateTimeImmutable $now = null
    ): ?array {
        $conditions = [];
        $parameters = [];
        $index = 0;
        $now ??= new DateTimeImmutable('now');

        foreach ($criteria as $criterion) {
            if (!$this->isUsable($criterion)) {
                continue;
            }

            [$condition, $conditionParameters] = $this->buildCondition($criterion, $index, $now);
            $conditions[] = $condition;
            $parameters += $conditionParameters;
            $index++;
        }

        if ([] === $conditions) {
            return null;
        }

        $operator = SmartBlockMatchType::Any === $matchType ? ' OR ' : ' AND ';

        return [
            'where' => '(' . implode($operator, $conditions) . ')',
            'parameters' => $parameters,
        ];
    }

    private function isUsable(StationPlaylistSmartBlockCriteria $criterion): bool
    {
        if (SmartBlockCriteriaField::CustomField === $criterion->field && null === $criterion->custom_field) {
            return false;
        }

        if (null === $criterion->value || '' === trim($criterion->value)) {
            return false;
        }

        return !$criterion->comparison->needsSecondValue()
            || (null !== $criterion->value2 && '' !== trim($criterion->value2));
    }

    /** @return array{string, array<string, mixed>} */
    private function buildCondition(
        StationPlaylistSmartBlockCriteria $criterion,
        int $index,
        DateTimeImmutable $now
    ): array {
        return match ($criterion->field) {
            SmartBlockCriteriaField::CustomField => $this->buildCustomFieldCondition($criterion, $index),
            SmartBlockCriteriaField::Duration => $this->buildNumericCondition('sm.length', $criterion, $index),
            SmartBlockCriteriaField::LastPlayed => $this->buildLastPlayedCondition($criterion, $index, $now),
            SmartBlockCriteriaField::Genre => $this->buildTextCondition('sm.genre', $criterion, $index),
            SmartBlockCriteriaField::Category => $this->buildCategoryCondition($criterion, $index),
            SmartBlockCriteriaField::Artist => $this->buildTextCondition('sm.artist', $criterion, $index),
            SmartBlockCriteriaField::Album => $this->buildTextCondition('sm.album', $criterion, $index),
            SmartBlockCriteriaField::Title => $this->buildTextCondition('sm.title', $criterion, $index),
        };
    }

    /** @return array{string, array<string, mixed>} */
    private function buildCategoryCondition(
        StationPlaylistSmartBlockCriteria $criterion,
        int $index
    ): array {
        $parameter = 'val' . $index;
        $categoryId = (int)$criterion->value;

        if (SmartBlockCriteriaComparison::IsNot === $criterion->comparison) {
            return [
                sprintf('(sm.category_id IS NULL OR sm.category_id != :%s)', $parameter),
                [$parameter => $categoryId],
            ];
        }

        return [
            sprintf('sm.category_id = :%s', $parameter),
            [$parameter => $categoryId],
        ];
    }

    /** @return array{string, array<string, mixed>} */
    private function buildTextCondition(
        string $column,
        StationPlaylistSmartBlockCriteria $criterion,
        int $index
    ): array {
        $parameter = 'val' . $index;
        $value = mb_strtolower((string)$criterion->value);

        return match ($criterion->comparison) {
            SmartBlockCriteriaComparison::Is => [
                sprintf('LOWER(%s) = :%s', $column, $parameter),
                [$parameter => $value],
            ],
            SmartBlockCriteriaComparison::IsNot => [
                sprintf('(%s IS NULL OR LOWER(%s) != :%s)', $column, $column, $parameter),
                [$parameter => $value],
            ],
            SmartBlockCriteriaComparison::Contains => [
                sprintf('LOWER(%s) LIKE :%s', $column, $parameter),
                [$parameter => '%' . $value . '%'],
            ],
            SmartBlockCriteriaComparison::NotContains => [
                sprintf('(%s IS NULL OR LOWER(%s) NOT LIKE :%s)', $column, $column, $parameter),
                [$parameter => '%' . $value . '%'],
            ],
            default => [$column . ' IS NOT NULL', []],
        };
    }

    /** @return array{string, array<string, mixed>} */
    private function buildNumericCondition(
        string $column,
        StationPlaylistSmartBlockCriteria $criterion,
        int $index
    ): array {
        $parameter = 'val' . $index;
        $value = (float)$criterion->value;

        if (SmartBlockCriteriaComparison::Between === $criterion->comparison) {
            $secondParameter = $parameter . 'b';
            $secondValue = (float)$criterion->value2;
            [$low, $high] = $value <= $secondValue ? [$value, $secondValue] : [$secondValue, $value];

            return [
                sprintf('%s BETWEEN :%s AND :%s', $column, $parameter, $secondParameter),
                [$parameter => $low, $secondParameter => $high],
            ];
        }

        $operator = match ($criterion->comparison) {
            SmartBlockCriteriaComparison::IsNot => '!=',
            SmartBlockCriteriaComparison::GreaterThan => '>',
            SmartBlockCriteriaComparison::LessThan => '<',
            default => '=',
        };

        return [sprintf('%s %s :%s', $column, $operator, $parameter), [$parameter => $value]];
    }

    /** @return array{string, array<string, mixed>} */
    private function buildLastPlayedCondition(
        StationPlaylistSmartBlockCriteria $criterion,
        int $index,
        DateTimeImmutable $now
    ): array {
        $alias = 'sh' . $index;
        $lastPlayed = sprintf(
            '(SELECT MAX(%1$s.timestamp_start) FROM %2$s %1$s WHERE %1$s.media = sm)',
            $alias,
            SongHistory::class
        );
        $parameter = 'val' . $index;
        $value = (float)$criterion->value;
        $daysAgo = static fn(float $days): DateTimeImmutable => $now->modify(
            sprintf('-%d seconds', (int)round($days * 86400))
        );

        if (SmartBlockCriteriaComparison::LessThan === $criterion->comparison) {
            return [
                sprintf('(%1$s IS NOT NULL AND %1$s > :%2$s)', $lastPlayed, $parameter),
                [$parameter => $daysAgo($value)],
            ];
        }

        if (SmartBlockCriteriaComparison::Between === $criterion->comparison) {
            $secondParameter = $parameter . 'b';
            $secondValue = (float)$criterion->value2;
            [$lowDays, $highDays] = $value <= $secondValue ? [$value, $secondValue] : [$secondValue, $value];

            return [
                sprintf(
                    '(%1$s IS NOT NULL AND %1$s BETWEEN :%2$s AND :%3$s)',
                    $lastPlayed,
                    $parameter,
                    $secondParameter
                ),
                [$parameter => $daysAgo($highDays), $secondParameter => $daysAgo($lowDays)],
            ];
        }

        return [
            sprintf('(%1$s IS NULL OR %1$s < :%2$s)', $lastPlayed, $parameter),
            [$parameter => $daysAgo($value)],
        ];
    }

    /** @return array{string, array<string, mixed>} */
    private function buildCustomFieldCondition(
        StationPlaylistSmartBlockCriteria $criterion,
        int $index
    ): array {
        $alias = 'smcf' . $index;
        $fieldParameter = 'field' . $index;
        $valueParameter = 'val' . $index;
        $parameters = [$fieldParameter => $criterion->custom_field];

        if (in_array(
            $criterion->comparison,
            [
                SmartBlockCriteriaComparison::GreaterThan,
                SmartBlockCriteriaComparison::LessThan,
                SmartBlockCriteriaComparison::Between,
            ],
            true
        )) {
            $value = (float)$criterion->value;

            if (SmartBlockCriteriaComparison::Between === $criterion->comparison) {
                $secondParameter = $valueParameter . 'b';
                $secondValue = (float)$criterion->value2;
                [$low, $high] = $value <= $secondValue ? [$value, $secondValue] : [$secondValue, $value];
                $valueCondition = sprintf(
                    'CAST(%s.value AS DECIMAL(18,4)) BETWEEN :%s AND :%s',
                    $alias,
                    $valueParameter,
                    $secondParameter
                );
                $parameters[$valueParameter] = $low;
                $parameters[$secondParameter] = $high;
            } else {
                $operator = SmartBlockCriteriaComparison::GreaterThan === $criterion->comparison ? '>' : '<';
                $valueCondition = sprintf(
                    'CAST(%s.value AS DECIMAL(18,4)) %s :%s',
                    $alias,
                    $operator,
                    $valueParameter
                );
                $parameters[$valueParameter] = $value;
            }
        } else {
            $value = mb_strtolower((string)$criterion->value);
            $useLike = in_array(
                $criterion->comparison,
                [SmartBlockCriteriaComparison::Contains, SmartBlockCriteriaComparison::NotContains],
                true
            );
            $valueCondition = sprintf(
                'LOWER(%s.value) %s :%s',
                $alias,
                $useLike ? 'LIKE' : '=',
                $valueParameter
            );
            $parameters[$valueParameter] = $useLike ? '%' . $value . '%' : $value;
        }

        $exists = sprintf(
            '(SELECT %1$s.id FROM %2$s %1$s WHERE %1$s.media = sm AND %1$s.field = :%3$s AND %4$s)',
            $alias,
            StationMediaCustomField::class,
            $fieldParameter,
            $valueCondition
        );
        $negative = in_array(
            $criterion->comparison,
            [SmartBlockCriteriaComparison::IsNot, SmartBlockCriteriaComparison::NotContains],
            true
        );

        return [($negative ? 'NOT EXISTS ' : 'EXISTS ') . $exists, $parameters];
    }
}
