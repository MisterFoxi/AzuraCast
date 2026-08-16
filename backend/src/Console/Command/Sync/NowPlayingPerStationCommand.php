<?php

declare(strict_types=1);

namespace App\Console\Command\Sync;

use App\Container\LoggerAwareTrait;
use App\Entity\Repository\StationRepository;
use App\Entity\Station;
use App\Sync\NowPlaying\Task\BuildQueueTask;
use App\Sync\NowPlaying\Task\NowPlayingTask;
use App\Utilities\Types;
use Monolog\LogRecord;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(
    name: 'azuracast:sync:nowplaying:station',
    description: 'Task to run the Now Playing worker task for a specific station.',
)]
final class NowPlayingPerStationCommand extends AbstractSyncCommand
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly StationRepository $stationRepo,
        private readonly BuildQueueTask $buildQueueTask,
        private readonly NowPlayingTask $nowPlayingTask
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('station', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->logToExtraFile('app_nowplaying.log');

        $stationName = Types::string($input->getArgument('station'));

        $station = $this->stationRepo->findByIdentifier($stationName);
        if (!($station instanceof Station)) {
            $io = new SymfonyStyle($input, $output);
            $io->error('Station not found.');
            return 1;
        }

        $this->logger->pushProcessor(
            function (LogRecord $record) use ($station) {
                $record->extra['station'] = [
                    'id' => $station->id,
                    'name' => $station->name,
                ];
                return $record;
            }
        );

        $this->logger->info('Starting Now Playing sync task.');

        try {
            $this->buildQueueTask->run($station);
        } catch (Throwable $e) {
            $this->logger->error(
                'Queue builder error: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }

        // Queue selection can roll back and clear the EntityManager while retrying
        // rejected candidates. Always reload the station before the remaining
        // Now Playing work so it never receives the detached pre-retry graph.
        $station = $this->stationRepo->findByIdentifier($stationName);
        if (!($station instanceof Station)) {
            $this->logger->error('Station disappeared after queue building.');
            $this->logger->popProcessor();
            return 1;
        }

        try {
            $this->nowPlayingTask->run($station);
        } catch (Throwable $e) {
            $this->logger->error(
                'Now Playing error: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }

        $this->logger->info('Now Playing sync task complete.');
        $this->logger->popProcessor();

        return 0;
    }
}
