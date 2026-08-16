<?php

declare(strict_types=1);

namespace App\Entity\Enums;

enum SmartBlockSort: string
{
    case Random = 'random';
    case Newest = 'newest';
    case Oldest = 'oldest';
    case Title = 'title';
    case Artist = 'artist';

    /** @return list<array{expression: string, direction: 'ASC'|'DESC'|null}> */
    public function getOrderBy(): array
    {
        return match ($this) {
            self::Random => [
                ['expression' => 'RAND()', 'direction' => null],
            ],
            self::Newest => [
                ['expression' => 'sm.uploaded_at', 'direction' => 'DESC'],
                ['expression' => 'sm.id', 'direction' => 'DESC'],
            ],
            self::Oldest => [
                ['expression' => 'sm.uploaded_at', 'direction' => 'ASC'],
                ['expression' => 'sm.id', 'direction' => 'ASC'],
            ],
            self::Title => [
                ['expression' => 'LOWER(sm.title)', 'direction' => 'ASC'],
                ['expression' => 'sm.id', 'direction' => 'ASC'],
            ],
            self::Artist => [
                ['expression' => 'LOWER(sm.artist)', 'direction' => 'ASC'],
                ['expression' => 'LOWER(sm.title)', 'direction' => 'ASC'],
                ['expression' => 'sm.id', 'direction' => 'ASC'],
            ],
        };
    }
}
