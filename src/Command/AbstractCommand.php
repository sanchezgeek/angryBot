<?php

declare(strict_types=1);

namespace App\Command;

use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Helper\OutputHelper;
use LogicException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

abstract class AbstractCommand extends Command
{
    use ConsoleInputAwareCommand;

    protected SymfonyStyle $io;
    protected OutputInterface $output;
    protected InputInterface $input;

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->withInput($input);
        $this->output = $output;
        $this->input = $input;
        $this->io = new SymfonyStyle($input, $output);
    }

    /**
     * @param callable[] $configurators
     */
    protected function configureWithConfigurators(array $configurators): void
    {
        foreach ($configurators as $configurator) {
            try {
                $configurator($this);
            } catch (LogicException $e) {
                if (str_contains($e->getMessage(), 'already exists')) {
                    OutputHelper::print(sprintf('configure %s: %s', OutputHelper::shortClassName(get_class($this)), $e->getMessage()));
                    continue;
                }
                throw $e;
            }
        }
    }
}
