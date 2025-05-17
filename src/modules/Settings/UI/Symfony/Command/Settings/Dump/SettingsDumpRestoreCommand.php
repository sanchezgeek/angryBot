<?php

namespace App\Settings\UI\Symfony\Command\Settings\Dump;

use App\Clock\ClockInterface;
use App\Command\AbstractCommand;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Helper\Json;
use App\Settings\Domain\Entity\SettingValue;
use App\Settings\Domain\Repository\SettingValueRepository;
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

#[AsCommand(name: 'settings:dump:restore')]
class SettingsDumpRestoreCommand extends AbstractCommand
{
    use ConsoleInputAwareCommand;

    public const PATH_ARG = 'path';
    public const PURGE_OPTION = 'purge';

    protected function configure(): void
    {
        $this
            ->addArgument(self::PATH_ARG, InputArgument::REQUIRED, 'Path to dump.')
            ->addOption(self::PURGE_OPTION, null, InputOption::VALUE_NEGATABLE, 'Purge settings before restore')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filepath = $this->paramFetcher->getStringArgument(self::PATH_ARG);
        $dump = Json::decode(file_get_contents($filepath));

        $values = array_map(static fn(array $data) => SettingValue::fromArray($data), $dump);

        if ($this->paramFetcher->getBoolOption(self::PURGE_OPTION)) {
            $existedValues = $this->settingValueRepository->findAll();
            foreach ($existedValues as $existedValue) {
                $this->settingValueRepository->remove($existedValue);
            }
        }

        $this->entityManager->wrapInTransaction(function() use ($values) {
            foreach ($values as $value) {
                $this->entityManager->persist($value);
            }
        });

        $this->io->note(sprintf('Settings restored. Qnt: %d', count($values)));

        return Command::SUCCESS;
    }

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SettingValueRepository $settingValueRepository,
        private ClockInterface $clock,
        ?string $name = null,
    ) {

        parent::__construct($name);
    }
}
