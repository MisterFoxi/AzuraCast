<?php

declare(strict_types=1);

namespace PureUnit;

use App\Entity\MediaGenre;
use App\Media\MediaGenreResolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class MediaGenreResolverTest extends TestCase
{
    public function testResolvesAnExistingCanonicalGenre(): void
    {
        $genre = new MediaGenre('Jpop', 'jpop');

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchOne')->willReturn('146');
        $connection->expects(self::never())->method('executeStatement');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('find')
            ->with(MediaGenre::class, 146)
            ->willReturn($genre);

        $resolver = new MediaGenreResolver($connection, $entityManager);

        self::assertSame($genre, $resolver->resolve('J-Pop'));
    }

    public function testCreatesAnUnknownGenreIdempotently(): void
    {
        $genre = new MediaGenre('Future Funk', 'futurefunk');
        $genre->is_custom = true;

        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::exactly(2))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(false, '200');
        $connection
            ->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::stringContains('ON DUPLICATE KEY UPDATE'),
                ['name' => 'Future Funk', 'normalized_name' => 'futurefunk']
            );

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('find')->willReturn($genre);

        $resolver = new MediaGenreResolver($connection, $entityManager);

        self::assertSame($genre, $resolver->resolve('  Future   Funk  '));
    }

    public function testDoesNothingForAnEmptyGenre(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchOne');
        $connection->expects(self::never())->method('executeStatement');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('find');

        $resolver = new MediaGenreResolver($connection, $entityManager);

        self::assertNull($resolver->resolve('  '));
    }
}
