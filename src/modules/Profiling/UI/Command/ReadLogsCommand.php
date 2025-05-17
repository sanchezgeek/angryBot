<?php

namespace App\Profiling\UI\Command;

use App\Command\AbstractCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Command\Mixin\SymbolAwareCommand;
use App\Profiling\Application\Storage\ProfilingPointStorage;
use App\Profiling\SDK\ProfilingContext;
use App\Profiling\SDK\ProfilingPointDto;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'profiling:read')]
class ReadLogsCommand extends AbstractCommand
{
    use SymbolAwareCommand;
    use PositionAwareCommand;

    private const UNIQID_ARG = 'unique-id';

    protected function configure()
    {
        $this
            ->configureSymbolArgs()
            ->addArgument(self::UNIQID_ARG, InputArgument::REQUIRED)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $uniqId = $this->paramFetcher->getStringArgument(self::UNIQID_ARG);

        $records = $this->storage->getRecordsByContext(ProfilingContext::create($uniqId));

        $records = array_combine(
            array_map(static fn (ProfilingPointDto $pointDto) => (string)$pointDto->timestampKey(), $records),
            $records
        );
        ksort($records);

        foreach ($records as $record) {
            $io->writeln(sprintf('%s: %s', $record->microTimestamp, sprintf('  %s', $record->info)));
        }

        return Command::SUCCESS;
    }

    public function __construct(
        private readonly ProfilingPointStorage $storage,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
