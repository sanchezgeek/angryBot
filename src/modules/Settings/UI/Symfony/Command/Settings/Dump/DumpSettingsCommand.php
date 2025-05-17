<?php

namespace App\Settings\UI\Symfony\Command\Settings\Dump;

use App\Clock\ClockInterface;
use App\Command\AbstractCommand;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Helper\Json;
use App\Settings\Domain\Repository\SettingValueRepository;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function file_put_contents;
use function sprintf;

#[AsCommand(name: 'settings:dump')]
class DumpSettingsCommand extends AbstractCommand
{
    use ConsoleInputAwareCommand;

    public const DIR_PATH_OPTION = 'dirPath';
    private const DUMPS_DEFAULT_DIR = __DIR__ . '/../../../../../../../../data/dump/settings';

    protected function configure(): void
    {
        $this
            ->addOption(self::DIR_PATH_OPTION, null, InputOption::VALUE_REQUIRED, 'Path to save dump.', self::DUMPS_DEFAULT_DIR)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dir = $this->paramFetcher->getStringOption(self::DIR_PATH_OPTION);
        if (!file_exists($dir)) {
            mkdir($dir);
        }
        if (!is_dir($dir)) {
            throw new RuntimeException(sprintf('Path "%s" already taken and it\'s not a directory', realpath($dir)));
        }
        $filepath = sprintf('%s/settings-%s.json', $dir, $this->clock->now()->format('Y-m-d_H:i:s'));

        $values = $this->settingValueRepository->findAll();
        var_dump($values);

        $dump = [];
        foreach ($values as $value) {
            $dump[] = $value->toArray();
        }

        file_put_contents($filepath, Json::encode($dump));
        $filepath = realpath($filepath);

        $this->io->info(sprintf('Dump saved to %s', $filepath));

        return Command::SUCCESS;
    }

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SettingValueRepository $settingValueRepository,
        private readonly ClockInterface $clock,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
