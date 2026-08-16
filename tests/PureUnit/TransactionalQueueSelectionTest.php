<?php

declare(strict_types=1);

namespace PureUnit;

use PHPUnit\Framework\TestCase;

final class TransactionalQueueSelectionTest extends TestCase
{
    public function testSelectionIsValidatedBeforeTransactionCommit(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../backend/src/Radio/AutoDJ/Queue.php'
        );

        self::assertIsString($source);
        self::assertStringContainsString('MAX_SELECTION_ATTEMPTS = 5', $source);
        self::assertStringContainsString('$this->dispatcher->dispatch($event);', $source);
        self::assertStringContainsString("if ([] !== \$event->getNextSongs() && !\$event->wasRejected())", $source);
        self::assertStringContainsString('$this->em->flush();', $source);
        self::assertStringContainsString('$connection->commit();', $source);
        self::assertStringContainsString('$connection->rollBack();', $source);
        self::assertStringContainsString('$this->em->clear();', $source);
        self::assertStringContainsString('$station = $this->em->refetch($station);', $source);

        $dispatchPosition = strpos($source, '$this->dispatcher->dispatch($event);');
        $commitPosition = strpos($source, '$connection->commit();', $dispatchPosition);
        self::assertNotFalse($dispatchPosition);
        self::assertNotFalse($commitPosition);
        self::assertGreaterThan($dispatchPosition, $commitPosition);
    }

    public function testDmcaUsesExplicitRejectionContract(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../backend/src/Radio/AutoDJ/DmcaComplianceListener.php'
        );

        self::assertIsString($source);
        self::assertStringContainsString(
            "\$event->rejectSelection('DMCA compliance validation failed.');",
            $source
        );
        self::assertStringNotContainsString('$event->setNextSongs(null)', $source);
    }

    public function testNowPlayingReloadsStationAfterTransactionalQueueBuild(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../backend/src/Console/Command/Sync/NowPlayingPerStationCommand.php'
        );

        self::assertIsString($source);

        $buildPosition = strpos($source, '$this->buildQueueTask->run($station);');
        self::assertNotFalse($buildPosition);

        $reloadPosition = strpos(
            $source,
            '$station = $this->stationRepo->findByIdentifier($stationName);',
            $buildPosition + 1
        );
        $nowPlayingPosition = strpos($source, '$this->nowPlayingTask->run($station);');

        self::assertNotFalse($reloadPosition);
        self::assertNotFalse($nowPlayingPosition);
        self::assertGreaterThan($buildPosition, $reloadPosition);
        self::assertGreaterThan($reloadPosition, $nowPlayingPosition);
    }
}
