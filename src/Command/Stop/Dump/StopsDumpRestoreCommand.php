<?php

namespace App\Command\Stop\Dump;

use App\Bot\Application\Service\RestoreOrder\StopRestoreFactory;
use App\Bot\Domain\Repository\StopRepository;
use App\Clock\ClockInterface;
use App\Command\AbstractCommand;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Command\PositionDependentCommand;
use App\Helper\Json;
use App\Tests\Functional\Command\Stop\Dump\StopsDumpRestoreCommandTest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function array_map;
use function file_get_contents;
use function sprintf;

/**
 * @see StopsDumpRestoreCommandTest
 */
#[AsCommand(name: 'sl:dump:restore')]
class StopsDumpRestoreCommand extends AbstractCommand implements PositionDependentCommand
{
    use ConsoleInputAwareCommand;
    use PositionAwareCommand;

    public const string PATH_ARG = 'path';
    public const string KEEP_IDS = 'restore-ids';

    protected function configure(): void
    {
        $this->addArgument(self::PATH_ARG, InputArgument::REQUIRED, 'Path to dump.');
        $this->addOption(self::KEEP_IDS, null, InputOption::VALUE_NEGATABLE, 'Restore also ids from dump?');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filepath = $this->paramFetcher->getStringArgument(self::PATH_ARG);
        $dump = Json::decode(file_get_contents($filepath));
        $restoreIds = $this->paramFetcher->getBoolOption(self::KEEP_IDS);

        $stops = array_map(fn(array $data) => $this->stopRestoreFactory->restore($data, $restoreIds), $dump);

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
        private readonly ClockInterface $clock,
        private readonly StopRestoreFactory $stopRestoreFactory,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
