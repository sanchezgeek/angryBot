<?php

namespace App\Command\Stop\Dump;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Repository\StopRepository;
use App\Clock\ClockInterface;
use App\Command\AbstractCommand;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Helper\Json;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function array_map;
use function file_get_contents;
use function sprintf;

/**
 * @see StopsDumpCommandTest
 */
#[AsCommand(name: 'sl:dump:restore')]
class StopsDumpRestoreCommand extends AbstractCommand
{
    use ConsoleInputAwareCommand;
    use PositionAwareCommand;

    public const PATH_ARG = 'path';

    protected function configure(): void
    {
        $this->addArgument(self::PATH_ARG, InputArgument::REQUIRED, 'Path to dump.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filepath = $this->paramFetcher->getStringArgument(self::PATH_ARG);
        $dump = Json::decode(file_get_contents($filepath));

        $stops = array_map(static fn(array $stopData) => Stop::fromArray($stopData), $dump);

        $this->entityManager->wrapInTransaction(function() use ($stops) {
            foreach ($stops as $stop) {
                $this->entityManager->persist($stop);
            }
        });

        $this->io->note(sprintf('Stops restored! Qnt: %d', count($stops)));

        return Command::SUCCESS;
    }

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StopRepository $stopRepository,
        PositionServiceInterface $positionService,
        private ClockInterface $clock,
        ?string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
