<?php

namespace App\Service\Application\Messenger\Job;

use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class GenerateSupervisorConfigsHandler
{
    const CONFIG_FILE_NAME_PREFIX = 'symbol-';

    public function __invoke(GenerateSupervisorConfigs $entry): void
    {
        $finder = new Finder();

        $finder->files()->in($this->configsPath);
        if ($finder->hasResults()) {
            foreach ($finder as $file) {
                if (!str_contains($file->getFilename(), self::CONFIG_FILE_NAME_PREFIX)) {
                    continue;
                }

                unlink($file->getRealPath());
            }
        }

        $templatePath = realpath($this->templatePath);
        $templateContent = file_get_contents($templatePath);
        foreach ($this->positionService->getOpenedPositionsSymbols() as $symbol) {
            $symbol = $symbol->value;

            $content = str_replace('%symbol%', $symbol, $templateContent);
            $filename = sprintf('%s%s.ini', self::CONFIG_FILE_NAME_PREFIX, $symbol);

            file_put_contents($this->configsPath . DIRECTORY_SEPARATOR . $filename, $content);
        }
    }

    public function __construct(
        private ByBitLinearPositionService $positionService,
        private string $configsPath,
        private string $templatePath,
    ) {
    }
}
