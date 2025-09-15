<?php

declare(strict_types=1);

namespace App\Info\Contract\Dto;

use Stringable;

abstract class AbstractDependencyInfo implements Stringable
{
    public function __construct(
        public string|array $info,
        public string $dependentOn,
    ) {
    }

    public function __toString()
    {
        return sprintf("dependsOn: %s\ninfo: %s", $this->dependentOn, $this->stringInfo());
    }

    public function stringInfo(string $delimiter = "\n"): string
    {
        if (is_array($this->info)) {
            $text = [];
            foreach ($this->info as $key => $item) {
                $text[] = $key ? sprintf('%s: %s', $key, $item) : $item;
            }

            $text = implode($delimiter, $text);
        } else {
            $text = $this->info;
        }

        return $text;
    }
}
