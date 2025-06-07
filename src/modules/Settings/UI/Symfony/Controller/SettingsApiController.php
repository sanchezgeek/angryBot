<?php

namespace App\Settings\UI\Symfony\Controller;

use App\Helper\OutputHelper;
use App\Output\Table\Dto\Style\CellStyle;
use App\Settings\Api\View\AppSettingGroupView;
use App\Settings\Api\View\AppSettingRowView;
use App\Settings\Application\Service\AppSettingsService;
use App\Settings\Application\Service\SettingAccessor;
use App\Settings\Application\Service\SettingsLocator;
use App\Settings\Application\Storage\Dto\AssignedSettingValue;
use App\Trading\Application\Symbol\SymbolProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SettingsApiController extends AbstractController
{
    public function __construct(
        private readonly SettingsLocator $settingsLocator,
        private readonly AppSettingsService $settingsProvider,
        private readonly SymbolProvider $symbolProvider,
    ) {
    }

    #[Route(path: '/list/{symbol}', requirements: ['symbol' => '\w+'])]
    public function all(?string $symbol = null): Response
    {
        $onlyOverrides = false;
        $symbol = $symbol !== null ? $this->symbolProvider->getOrInitialize($symbol) : null;

        $items = [];

        foreach ($this->settingsLocator->getRegisteredSettingsGroups() as $group) {
            $groupValuesRows = [];
            foreach ($groupCases = $group::cases() as $settKey => $setting) {
                $values = $this->settingsProvider->getAllSettingAssignedValuesCollection($setting);

                if ($symbol) {
                    $values = $values->filterByAccessor(SettingAccessor::withAlternativesAllowed($setting, $symbol));
                }

                // from initially fetched collection
                $settingFallbackValue = $values->getFallbackValueIfPresented();

                $valuesToForeach = $onlyOverrides ? array_filter($values->getItems(), static fn(AssignedSettingValue $value) => !$value->isDefault()) : $values;
                if (!$valuesToForeach) {
                    continue;
                }

//                if (!$settingFallbackValue) {
//                    $groupValuesRows[] = DataRow::default([$setting->getSettingKey(), Cell::align(CellAlign::CENTER, '---'), Cell::align(CellAlign::CENTER, '---')]);
//                    $groupValuesRows[] = new AppSettingRowView(
//                        ,
//                        $settingKey,
//                        $assignedValue,
//                        $assignedValue->info . ($assignedValue->isDefault() ? '   ' : '')
//                    );
//                }

                // add removed fallback (default value must be shown)
                if ($settingFallbackValue && $onlyOverrides && reset($valuesToForeach) != $settingFallbackValue) {
                    $valuesToForeach = array_merge([$settingFallbackValue], $valuesToForeach);
                }

                foreach ($valuesToForeach as $assignedValue) {
                    $isFallbackValue = $assignedValue->isFallbackValue();

                    $fullKey = $assignedValue->fullKey;
                    $baseKey = $setting->getSettingKey();

                    $style = CellStyle::default();
                    if ($isFallbackValue) {
                        $settingKey = $baseKey;
                    } else {
                        $settingKey = str_replace($baseKey, '', $fullKey);
                        $style = CellStyle::right();
                    }

                    $groupValuesRows[] = new AppSettingRowView(
                        $isFallbackValue,
                        $settingKey,
                        $assignedValue,
                        $assignedValue->info . ($assignedValue->isDefault() ? '   ' : '')
                    );
    //                $printedSettingRows[] = DataRow::default(
    //                    [
    //                        Cell::default($settingKey)->addStyle($style),
    //                        (string)$assignedValue,
    //                        Cell::default(
    //                            $assignedValue->info . ($assignedValue->isDefault() ? '   ' : '')
    //                        )->addStyle(new CellStyle(fontColor: $assignedValue->isDefault() ? Color::GRAY : Color::DEFAULT)),
    //                    ]
    //                );
                }
//                if ($printedSettingRows && $settKey !== array_key_last($groupCases)) $printedSettingRows[] = new OutputTableSeparatorView();
            }

            if ($groupValuesRows) {
                $items[] = new AppSettingGroupView(OutputHelper::shortClassName($group), ...$groupValuesRows);
//                $groupHeaderRows = [
//                    (!$items || get_class(end($items)) !== SeparatorRow::class) ? DataRow::separated([$groupHeaderCell]) : DataRow::default([$groupHeaderCell])
//                ];

//                $items = array_merge($items, [$groupHeaderCell], $groupValuesRows);
            }
        }

        return new JsonResponse($items);
    }
}
