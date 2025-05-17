<?php

namespace App\Command\Stop\Dump;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Repository\StopRepository;
use App\Clock\ClockInterface;
use App\Command\AbstractCommand;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Helper\Json;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function file_put_contents;
use function implode;
use function in_array;
use function sprintf;

/**
 * @see StopsDumpCommandTest
 */
#[AsCommand(name: 'sl:dump')]
class StopsDumpCommand extends AbstractCommand
{
    use ConsoleInputAwareCommand;
    use PositionAwareCommand;

    private const DUMPS_DEFAULT_DIR = __DIR__ . '/../../../../_data/dump';

    public const DIR_PATH_OPTION = 'dirPath';
    public const MODE_OPTION = 'mode';
    public const DELETE_DUMPED_STOPS_OPTION = 'delete';

    public const MODE_ACTIVE = 'active';
    public const MODE_ALL = 'all';

    private const MODES = [self::MODE_ACTIVE, self::MODE_ALL];

    protected function configure(): void
    {
        $this
            ->configurePositionArgs()
            ->addOption(self::DIR_PATH_OPTION, null, InputOption::VALUE_REQUIRED, 'Path to save dump.', self::DUMPS_DEFAULT_DIR)
            ->addOption(self::MODE_OPTION, '-m', InputOption::VALUE_REQUIRED, 'Mode (' . implode(', ', self::MODES) . ')', self::MODE_ALL)
            ->addOption(self::DELETE_DUMPED_STOPS_OPTION, '-d', InputOption::VALUE_NEGATABLE, 'Delete dumped stops', false)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $positionSide = $this->getPositionSide();
        $mode = $this->getMode();
        $deleteDumpedStops = $this->paramFetcher->getBoolOption(self::DELETE_DUMPED_STOPS_OPTION);

        $dir = $this->paramFetcher->getStringOption(self::DIR_PATH_OPTION);
        $filepath = sprintf('%s/%s.%s.json', $dir, $positionSide->value, $this->clock->now()->format('Y-m-d_H:i:s'));

        if ($mode === self::MODE_ALL) {
            $stops = $this->stopRepository->findAllByPositionSide($this->getSymbol(), $positionSide);
        }

        if ($mode === self::MODE_ACTIVE) {
            $stops = $this->stopRepository->findActive(
                symbol: $this->getSymbol(),
                side: $positionSide,
                qbModifier: function (QueryBuilder $qb) use ($positionSide) {
                    $priceField = $qb->getRootAliases()[0] . '.price';

                    $qb->orderBy($priceField, $positionSide->isShort() ? 'ASC' : 'DESC');
                }
            );
        }

        if (!$stops) {
            $this->io->info('Stops not found!'); return Command::SUCCESS;
        }

        $dump = [];
        foreach ($stops as $stop) {
            $dump[] = $stop->toArray();
        }
        file_put_contents($filepath, Json::encode($dump));

        if ($deleteDumpedStops) {
            $this->entityManager->wrapInTransaction(function() use ($stops) {
                foreach ($stops as $stop) {
                    $this->entityManager->remove($stop);
                }
            });

            $this->io->note(sprintf('Stops removed! Qnt: %d', count($stops)));
        }

        $this->io->info(sprintf('Dump saved to %s', $filepath));

        return Command::SUCCESS;
    }

    private function getMode(): string
    {
        $mode = $this->paramFetcher->getStringOption(self::MODE_OPTION);
        if (!in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException(
                sprintf('Invalid $mode provided (%s)', $mode),
            );
        }

        return $mode;
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
