<?php

namespace App\Settings\UI\Symfony\Command\Settings\Dump;

use App\Command\AbstractCommand;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Helper\Json;
use App\Settings\Application\Service\Restore\SettingValueRestoreFactory;
use App\Settings\Application\Service\SettingsCache;
use App\Settings\Domain\Repository\SettingValueRepository;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

use function array_map;
use function file_get_contents;
use function sprintf;

#[AsCommand(name: 'settings:dump:restore')]
class SettingsDumpRestoreCommand extends AbstractCommand
{
    use ConsoleInputAwareCommand;

    public const string PATH_ARG = 'path';
    public const string AUTO_OPTION = 'auto';
    public const string PURGE_OPTION = 'purge';

    protected function configure(): void
    {
        $this
            ->addArgument(self::PATH_ARG, InputArgument::OPTIONAL, 'Path to dump.')
            ->addOption(self::PURGE_OPTION, null, InputOption::VALUE_NEGATABLE, 'Purge settings before restore')
            ->addOption(self::AUTO_OPTION, null, InputOption::VALUE_NEGATABLE, 'Find stored settings automatically?')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($auto = $this->paramFetcher->getBoolOption(self::AUTO_OPTION)) {
            $finder = new Finder();
            $finder->files()->in($this->defaultSettingsPath);
            if (!$finder->hasResults()) {
                $this->io->info('Settings dumps not found');
                return Command::SUCCESS;
            } else {
                $storedSettings = iterator_to_array($finder);
                $paths = array_keys($storedSettings);

                $ask = array_map(static fn (string $filePath, int $key) => sprintf('%d: %s', $key, $filePath), $paths, array_keys($paths));
                $key = $this->io->ask(sprintf("Found %d settings dumps. Which one you want to restore?:\n\n%s", count($paths), implode("\n", $ask)));

                if (!$filepath = $paths[$key] ?? null) {
                    throw new InvalidArgumentException('Please select one of listed key');
                }
            }
        } else {
            $filepath = $this->paramFetcher->getStringArgument(self::PATH_ARG);
        }

        $dump = Json::decode(file_get_contents($filepath));

        $values = array_map(fn(array $data) => $this->settingValueRestoreService->restore($data), $dump);

        $this->entityManager->wrapInTransaction(function() use ($values) {
            if ($this->paramFetcher->getBoolOption(self::PURGE_OPTION)) {
                $existedValues = $this->settingValueRepository->findAll();
                foreach ($existedValues as $existedValue) {
                    $this->settingValueRepository->remove($existedValue);
                }
            }

            foreach ($values as $value) {
                $this->settingValueRepository->save($value);
            }
        });

        $this->io->note(sprintf('Settings restored. Qnt: %d', count($dump)));
        $this->settingsCache->clear();

        if ($auto) {
            unlink($filepath);
        }

        return Command::SUCCESS;
    }

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SettingValueRepository $settingValueRepository,
        private readonly SettingValueRestoreFactory $settingValueRestoreService,
        private readonly SettingsCache $settingsCache,
        private readonly string $defaultSettingsPath,
        ?string $name = null,
    ) {

        parent::__construct($name);
    }
}
