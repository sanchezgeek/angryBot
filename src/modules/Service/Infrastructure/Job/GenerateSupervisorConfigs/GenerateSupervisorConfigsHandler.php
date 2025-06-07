<?php

namespace App\Service\Infrastructure\Job\GenerateSupervisorConfigs;

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

        $prevContent = [];
        $finder->files()->in($this->configsPath);
        if ($finder->hasResults()) {
            foreach ($finder as $file) {
                if (!str_contains($file->getFilename(), self::CONFIG_FILE_NAME_PREFIX)) {
                    continue;
                }
                $prevContent[$file->getFilename()] = file_get_contents($file->getRealPath());

                unlink($file->getRealPath());
            }
        }
        sort($prevContent);
        $prevContent = json_encode($prevContent);

        $newContent = [];
        $templatePath = realpath($this->templatePath);
        $templateContent = file_get_contents($templatePath);
        foreach ($this->positionService->getOpenedPositionsSymbols() as $symbol) {
            $symbol = $symbol->name();

            $content = str_replace('%symbol%', $symbol, $templateContent);
            $filename = sprintf('%s%s.ini', self::CONFIG_FILE_NAME_PREFIX, $symbol);

            file_put_contents($this->configsPath . DIRECTORY_SEPARATOR . $filename, $content);

            $newContent[$filename] = $content;
        }
        sort($newContent);
        $newContent = json_encode($newContent);

        if ($prevContent !== $newContent) {
            shell_exec('/usr/bin/supervisorctl reload');
        }
    }

    public function __construct(
        private ByBitLinearPositionService $positionService,
        private string $configsPath,
        private string $templatePath,
    ) {
    }
}
