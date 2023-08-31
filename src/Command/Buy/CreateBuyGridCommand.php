<?php

namespace App\Command\Buy;

use App\Bot\Application\Service\Orders\BuyOrderService;
use App\Domain\Position\ValueObject\Side;
use App\Helper\Json;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_merge;
use function random_int;
use function round;
use function sprintf;

#[AsCommand(name: 'buy:grid')]
class CreateBuyGridCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('position_side', InputArgument::REQUIRED, 'Position side (sell|buy)')
            ->addArgument('volume', InputArgument::REQUIRED, 'Buy volume')
            ->addArgument('from_price', InputArgument::REQUIRED, 'From price')
            ->addArgument('to_price', InputArgument::REQUIRED, 'To price')
            ->addArgument('step', InputArgument::REQUIRED, 'Step')
            ->addOption('withContext', 'c', InputOption::VALUE_OPTIONAL, 'Additional context')
        ;
    }

    private function getJsonParam(string $value, string $name): array
    {
        try {
            return Json::decode($value);
        } catch (\Throwable $e) {
            throw new InvalidArgumentException(
                sprintf('Invalid \'%s\' JSON param provided ("%s" given).', $name, $value)
            );
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $positionSide = $input->getArgument('position_side');
            $triggerDelta = 1;
//            $triggerDelta = $input->getArgument('trigger_delta') ?? null;
            $volume = $input->getArgument('volume');
            $fromPrice = $input->getArgument('from_price');
            $toPrice = $input->getArgument('to_price');
            $step = $input->getArgument('step');

            if (!$positionSide = Side::tryFrom($positionSide)) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $step provided (%s)', $step),
                );
            }
            if (!(float)$step) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $step provided (%s)', $step),
                );
            }
            if (!(float)$toPrice) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $toPrice provided (%s)', $toPrice),
                );
            }
            if (!(float)$fromPrice) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $fromPrice provided (%s)', $fromPrice),
                );
            }
            if (!(string)$volume) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $price provided (%s)', $volume),
                );
            }

            $context = ['uniqid' => $uniqueId = \uniqid('inc-create', true)];
            if ($withContext = $input->getOption('withContext')) {
                $context = array_merge($context, $this->getJsonParam($withContext, 'withContext'));
            }

            for ($price = $toPrice; $price > $fromPrice; $price-=$step) {
                $rand = round(random_int(-7, 8) * 0.4, 2);

                $price += $rand;

                $this->buyOrderService->create($positionSide, $price, $volume, $triggerDelta, $context);
            }

            $result = [
                \sprintf('BuyOrders uniqueID: %s', $uniqueId),
            ];

            $io->success($result);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    public function __construct(
        private readonly BuyOrderService $buyOrderService,
        string $name = null,
    ) {
        parent::__construct($name);
    }
}
