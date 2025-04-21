<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

readonly class ForwardedCommandExecutor
{
    public function __construct(private Application $consoleApplication)
    {
    }

    public function execute(string $commandName, InputInterface $input, OutputInterface $output, array $params): void
    {
        $command = $this->consoleApplication->get($commandName);

        $forwardedCommandArguments = [];
        $forwardedCommandOptions = [];
        foreach ($command->getDefinition()->getArguments() as $argument) {
            $forwardedCommandArguments[] = $argument->getName();
        }
        foreach ($command->getDefinition()->getOptions() as $option) {
            $forwardedCommandOptions[] = $option->getName();
        }

        $args = array_merge(
            ['command' => $commandName],
            array_filter($input->getArguments(), static fn ($name) => in_array($name, $forwardedCommandArguments, true), ARRAY_FILTER_USE_KEY)
        );

        foreach ($input->getOptions() as $name => $value) {
            if (!in_array($name, $forwardedCommandOptions, true)) continue;
            $value = (string)$value;
            if ($value === '') continue;

            $args[sprintf('--%s', $name)] = $value;
        }

        $input = new ArrayInput(array_merge($args, $params));

        $this->consoleApplication->doRun($input, $output);
    }
}
