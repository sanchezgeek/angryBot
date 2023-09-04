<?php

declare(strict_types=1);

namespace App\Console;

use App\Helper\Json;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;

use function is_numeric;
use function sprintf;
use function str_ends_with;
use function substr;

final class ConsoleParamFetcher
{
    private const ARGUMENT_PARAM_CAPTION = 'argument';
    private const OPTION_PARAM_CAPTION = 'option';

    public function __construct(private InputInterface $input)
    {
    }

    public function getStringArgument(string $name): string
    {
        return $this->input->getArgument($name);
    }

    public function getStringOption(string $name, string $nullOptionErrorMessage = null): string
    {
        $value = $this->input->getOption($name);

        if ($value === null) {
            throw new InvalidArgumentException(
                $nullOptionErrorMessage ?: sprintf('Option `%s` is required but not provided.', $name)
            );
        }

        return $value;
    }

    public function getIntArgument(string $name): int
    {
        return $this->fetchIntValue($this->input->getArgument($name), $name, self::ARGUMENT_PARAM_CAPTION);
    }

    public function getIntOption(string $name, string $nullOptionErrorMessage = null): int
    {
        $value = $this->input->getOption($name);

        if ($value === null) {
            throw new InvalidArgumentException(
                $nullOptionErrorMessage ?: sprintf('Option `%s` is required but not provided.', $name)
            );
        }

        return $this->fetchIntValue($value, $name, self::OPTION_PARAM_CAPTION);
    }

    public function getFloatArgument(string $name): float
    {
        return $this->fetchFloatValue($this->input->getArgument($name), $name, self::ARGUMENT_PARAM_CAPTION);
    }

    public function getFloatOption(string $name, string $nullOptionErrorMessage = null): float
    {
        $value = $this->input->getOption($name);

        if ($value === null) {
            throw new InvalidArgumentException(
                $nullOptionErrorMessage ?: sprintf('Option `%s` is required but not provided.', $name)
            );
        }

        return $this->fetchFloatValue($value, $name, self::OPTION_PARAM_CAPTION);
    }

    public function getPercentArgument(string $name): int
    {
        return $this->fetchPercentValue($this->input->getArgument($name), $name, self::ARGUMENT_PARAM_CAPTION);
    }

    public function getPercentOption(string $name, string $nullOptionErrorMessage = null): int
    {
        $value = $this->input->getOption($name);
        if ($value === null) {
            throw new InvalidArgumentException(
                $nullOptionErrorMessage ?: sprintf('Option `%s` is required but not provided.', $name)
            );
        }

        return $this->fetchPercentValue($value, $name, self::OPTION_PARAM_CAPTION);
    }

    public function getBoolOption(string $name): ?bool
    {
        if ($this->input->getOption($name) !== null) {
            return $this->input->getOption($name) === true;
        }

        return null;
    }

    public function getJsonArrayOption(string $name): ?array
    {
        if ($this->input->getOption($name) !== null) {
            return $this->fetchJsonArrayValue($this->input->getOption($name), $name);
        }

        return null;
    }

    private function fetchIntValue(string $value, string $name, string $paramTypeCaption): int
    {
        if (!is_numeric($value) || (string)(int)$value !== $value) {
            throw new InvalidArgumentException(
                sprintf('Invalid \'%s\' INT %s provided ("%s" given).', $name, $paramTypeCaption, $value)
            );
        }

        return (int)$value;
    }

    private function fetchFloatValue(string $value, string $name, string $paramTypeCaption): float
    {
        if (!is_numeric($value) || (string)($floatValue = (float)$value) === '') {
            throw new InvalidArgumentException(
                sprintf('Invalid \'%s\' FLOAT %s provided ("%s" given).', $name, $paramTypeCaption, $value)
            );
        }

        return $floatValue;
    }

    private function fetchPercentValue(string $value, string $name, string $paramTypeCaption): int
    {
        if (
            !str_ends_with($value, '%')
            || (!is_numeric(substr($value, 0, -1)))
        ) {
            throw new InvalidArgumentException(
                sprintf('Invalid \'%s\' PERCENT %s provided ("%s" given).', $name, $paramTypeCaption, $value)
            );
        }

        return (int)substr($value, 0, -1);
    }

    private function fetchJsonArrayValue(string $value, string $name): array
    {
        try {
            return Json::decode($value);
        } catch (\Throwable $e) {
            throw new InvalidArgumentException(
                sprintf('Invalid \'%s\' JSON param provided ("%s" given).', $name, $value)
            );
        }
    }
}
