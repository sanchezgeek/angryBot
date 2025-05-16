<?php

namespace App\Alarm\UI\Command;

use App\Alarm\Application\Settings\AlarmSettings;
use App\Command\AbstractCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Command\Mixin\SymbolAwareCommand;
use App\Domain\Position\ValueObject\Side;
use App\Settings\Application\DynamicParameters\Evaluation\AppDynamicParameterEvaluator;
use App\Settings\Application\Service\AppSettingsService;
use App\Settings\Application\Service\SettingAccessor;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'alarm:edit')]
class EditAlarmCommand extends AbstractCommand
{
    use SymbolAwareCommand;
    use PositionAwareCommand;

    private const SIDE_OPTION = 'side';

    private const LOSS_OPTION = 'loss';
    private const PROFIT_OPTION = 'profit';
    private const PNL_PERCENT_OPTION = 'percent';

    private const ENABLE_OPTION = 'enable';
    private const DISABLE_OPTION = 'disable';

    private const ROOT_OPTION = 'root';

    protected function configure()
    {
        $this
            ->configureSymbolArgs()
            ->addOption(self::LOSS_OPTION, null, InputOption::VALUE_NEGATABLE)
            ->addOption(self::PROFIT_OPTION, null, InputOption::VALUE_NEGATABLE)
            ->addOption(self::PNL_PERCENT_OPTION, null, InputOption::VALUE_OPTIONAL)
            ->addOption(self::ENABLE_OPTION, null, InputOption::VALUE_NEGATABLE)
            ->addOption(self::DISABLE_OPTION, null, InputOption::VALUE_NEGATABLE)
            ->addOption(self::SIDE_OPTION, null, InputOption::VALUE_REQUIRED)
            ->addOption(self::ROOT_OPTION, null, InputOption::VALUE_NEGATABLE)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($percent = $this->paramFetcher->percentOption(self::PNL_PERCENT_OPTION)) {
            $symbol = $this->getSymbol();
            $sideRaw = $this->paramFetcher->getStringOption(self::SIDE_OPTION, false);
            $side = $sideRaw ? Side::from($sideRaw) : null;

            $this->settingsService->set(SettingAccessor::exact(AlarmSettings::AlarmOnProfitPnlPercent, $symbol, $side), (int)$percent);
        } else {
            $setting = match (true) {
                $this->paramFetcher->getBoolOption(self::LOSS_OPTION) => AlarmSettings::AlarmOnLossEnabled,
                $this->paramFetcher->getBoolOption(self::PROFIT_OPTION) => AlarmSettings::AlarmOnProfitEnabled,
                default => throw new InvalidArgumentException('One of "loss" or "profit" options must be selected'),
            };

            $enable = match (true) {
                $this->paramFetcher->getBoolOption(self::ENABLE_OPTION) => true,
                $this->paramFetcher->getBoolOption(self::DISABLE_OPTION) => false,
                default => throw new InvalidArgumentException('One of "enable" or "disable" options must be selected'),
            };

            if ($this->paramFetcher->getBoolOption(self::ROOT_OPTION)) {
                $symbol = null;
                $side = null;
            } else {
                $symbol = $this->getSymbol();
                $sideRaw = $this->paramFetcher->getStringOption(self::SIDE_OPTION, false);
                $side = $sideRaw ? Side::from($sideRaw) : null;
            }

            $this->settingsService->set(SettingAccessor::exact($setting, $symbol, $side), $enable);
        }


        return Command::SUCCESS;
    }

    public function __construct(
        private readonly AppSettingsService $settingsService,
        private readonly AppDynamicParameterEvaluator $parameterEvaluator,
        string $name = null,
    ) {
        parent::__construct($name);
    }
}
