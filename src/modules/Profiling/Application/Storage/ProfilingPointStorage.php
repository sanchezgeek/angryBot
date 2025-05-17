<?php

declare(strict_types=1);

namespace App\Profiling\Application\Storage;

use App\Profiling\SDK\ProfilingContext;
use App\Profiling\SDK\ProfilingPointDto;
use Exception;

final readonly class ProfilingPointStorage
{
    public function __construct(private string $filePath)
    {
    }

    public function reset(): void
    {
        if (file_exists($this->filePath)) {
            unlink($this->filePath);
        }
    }

    public function save(ProfilingPointDto $dto): void
    {
        $data = json_encode($dto);

        $this->write($data);
    }

    private function write(string $row): void
    {
        $this->prepareStorage();

        file_put_contents(realpath($this->filePath), $row . ',' . PHP_EOL, FILE_APPEND);
    }

    private function prepareStorage(): void
    {
        if (!file_exists($this->filePath)) {
            $dir = dirname($this->filePath);
            if (!is_dir(realpath($dir))) {
                throw new Exception('Dir not found');
            }

            touch($this->filePath);
        }
    }

    /**
     * @return ProfilingPointDto[]
     */
    public function getRecordsByContext(ProfilingContext $profilingContext): array
    {
        $str = sprintf('[%s]', rtrim(file_get_contents($this->filePath), "\n,"));
        $data = json_decode($str, true);

        $result = [];
        foreach ($data as $item) {
            $point = ProfilingPointDto::fromStored($item);

            if ($point->context && $point->context == $profilingContext) {
                $result[] = $point;
            }
        }

        return $result;
    }
}
