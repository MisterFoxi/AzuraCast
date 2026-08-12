<?php

declare(strict_types=1);

namespace App\Entity\Enums;

use OpenApi\Attributes as OA;

#[OA\Schema(type: 'string')]
enum SmartBlockMatchType: string
{
    case All = 'all';
    case Any = 'any';

    public static function default(): self
    {
        return self::All;
    }
}
