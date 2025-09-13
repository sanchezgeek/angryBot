<?php

declare(strict_types=1);

namespace App\UserInteraction\HandlerMessage;

use App\Command\AbstractCommand;
use App\modules\UserInteraction\HandlerMessage\Dto\HandlerMessageReference;
use App\modules\UserInteraction\HandlerMessage\Factory\HandlerMessageReferenceFactory;
use App\Trading\Application\UseCase\TruncateOrders\TruncateOrdersEntry;
use ReflectionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class AbstractExecuteHandlerMessageCommand extends AbstractCommand
{
    private HandlerMessageReference $handlerMessageReference;

    abstract protected function getHandledMessageClass(): string;

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);

        $this->handlerMessageReference = HandlerMessageReferenceFactory::fromTaskEntryClass(TruncateOrdersEntry::class);
    }

    /**
     * @throws ReflectionException
     */
    protected function initializeHandledMessage(): object
    {
        $this->io->note(sprintf('Handled entry: %s', $this->handlerMessageReference->className));

        $input = [];
        foreach ($this->handlerMessageReference->parameters as $parameter) {
            $options = $parameter->options;

            $userInput = match (true) {
                $options !== null && count($options) => $this->io->choice(sprintf("`%s`", $parameter->title), $options, array_flip($options)[$options[array_key_first($options)]]),
                default => $this->io->ask(sprintf('`%s`', $parameter->title))
            };

            $input[$parameter->argName] = $parameter->resolveRawUserInput($userInput);
        }

        return $this->handlerMessageReference->makeTaskEntryFromUserInput($input);
    }
}
