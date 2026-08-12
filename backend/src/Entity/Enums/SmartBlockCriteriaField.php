<?php

declare(strict_types=1);

namespace App\Entity\Enums;

use OpenApi\Attributes as OA;

#[OA\Schema(type: 'string')]
enum SmartBlockCriteriaField: string
{
    case Genre = 'genre';
    case Category = 'category';
    case Artist = 'artist';
    case Album = 'album';
    case Title = 'title';
    case Duration = 'duration';
    case CustomField = 'custom_field';
    case LastPlayed = 'last_played_days_ago';

    public static function default(): self
    {
        return self::Genre;
    }

    public function isNumeric(): bool
    {
        return self::Duration === $this || self::LastPlayed === $this;
    }
}
