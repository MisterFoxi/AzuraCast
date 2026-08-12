<?php

declare(strict_types=1);

namespace App\Entity\Enums;

use OpenApi\Attributes as OA;

#[OA\Schema(type: 'string')]
enum SmartBlockCriteriaComparison: string
{
    case Is = 'is';
    case IsNot = 'is_not';
    case Contains = 'contains';
    case NotContains = 'not_contains';
    case GreaterThan = 'greater_than';
    case LessThan = 'less_than';
    case Between = 'between';

    public static function default(): self
    {
        return self::Is;
    }

    public function needsSecondValue(): bool
    {
        return self::Between === $this;
    }

    /** @return list<self> */
    public static function textComparisons(): array
    {
        return [self::Is, self::IsNot, self::Contains, self::NotContains];
    }

    /** @return list<self> */
    public static function numericComparisons(): array
    {
        return [self::Is, self::IsNot, self::GreaterThan, self::LessThan, self::Between];
    }
}
