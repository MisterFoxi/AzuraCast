<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Interfaces\IdentifiableEntityInterface;
use Doctrine\ORM\Mapping as ORM;

#[
    ORM\Entity,
    ORM\Table(name: 'media_genre_aliases'),
    ORM\UniqueConstraint(name: 'UNIQ_media_genre_aliases_normalized_alias', columns: ['normalized_alias'])
]
final class MediaGenreAlias implements IdentifiableEntityInterface
{
    use Traits\HasAutoIncrementId;
    use Traits\TruncateStrings;

    #[
        ORM\ManyToOne,
        ORM\JoinColumn(name: 'genre_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')
    ]
    public readonly MediaGenre $genre;

    #[ORM\Column(nullable: false, insertable: false, updatable: false)]
    public private(set) int $genre_id;

    #[ORM\Column(length: 255)]
    public string $alias {
        set => $this->truncateString($value);
    }

    #[ORM\Column(length: 255)]
    public string $normalized_alias {
        set => $this->truncateString($value);
    }

    public function __construct(MediaGenre $genre, string $alias, string $normalizedAlias)
    {
        $this->genre = $genre;
        $this->alias = $alias;
        $this->normalized_alias = $normalizedAlias;
    }
}
