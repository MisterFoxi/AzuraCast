<?php

declare(strict_types=1);

namespace App\Entity\Enums;

use OpenApi\Attributes as OA;

#[OA\Schema(type: 'string')]
enum SmartBlockType: string
{
    case Static = 'static';
    case Dynamic = 'dynamic';

    public static function default(): self
    {
        return self::Dynamic;
    }
}
