<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Interfaces\IdentifiableEntityInterface;
use Doctrine\ORM\Mapping as ORM;
use Stringable;

#[
    ORM\Entity,
    ORM\Table(name: 'media_genres'),
    ORM\UniqueConstraint(name: 'UNIQ_media_genres_id3_id', columns: ['id3_id']),
    ORM\UniqueConstraint(name: 'UNIQ_media_genres_normalized_name', columns: ['normalized_name'])
]
final class MediaGenre implements IdentifiableEntityInterface, Stringable
{
    use Traits\HasAutoIncrementId;
    use Traits\TruncateStrings;

    #[ORM\Column(type: 'smallint', nullable: true)]
    public ?int $id3_id = null;

    #[ORM\Column(length: 255)]
    public string $name {
        set => $this->truncateString($value);
    }

    #[ORM\Column(length: 255)]
    public string $normalized_name {
        set => $this->truncateString($value);
    }

    #[ORM\Column(options: ['default' => true])]
    public bool $is_active = true;

    #[ORM\Column(options: ['default' => false])]
    public bool $is_custom = false;

    public function __construct(string $name, string $normalizedName)
    {
        $this->name = $name;
        $this->normalized_name = $normalizedName;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
