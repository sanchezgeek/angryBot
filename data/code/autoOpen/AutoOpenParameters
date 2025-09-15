<?php

declare(strict_types=1);

namespace App\Trading\Application\AutoOpen;

use App\Domain\Trading\Enum\RiskLevel;
use App\Info\Application\Attribute\AutoDependencyAttribute;
use BackedEnum;
use JsonSerializable;
use ReflectionClass;
use RuntimeException;

final class AutoOpenParameters implements JsonSerializable
{
    public function __construct(
        public RiskLevel $usedRiskLevel,

        #[AutoDependencyAttribute(dependsOn: RiskLevel::Cautious, resultValue: 0.8)]
        #[AutoDependencyAttribute(dependsOn: RiskLevel::Conservative, resultValue: 1)]
        #[AutoDependencyAttribute(dependsOn: RiskLevel::Aggressive, resultValue: 1.5)]
        public float $minPercentOfDepositToUseAsMargin,

        #[AutoDependencyAttribute(dependsOn: RiskLevel::Cautious, resultValue: 2.5)]
        #[AutoDependencyAttribute(dependsOn: RiskLevel::Conservative, resultValue: 4)]
        #[AutoDependencyAttribute(dependsOn: RiskLevel::Aggressive, resultValue: 6)]
        public float $maxPercentOfDepositToUseAsMargin,
    ) {
    }

    public static function createFromDefaults(RiskLevel $riskLevel): self
    {
        $dependencies = [
            RiskLevel::class => $riskLevel
        ];

        $ref = new ReflectionClass(self::class);
        $parameters = $ref->getConstructor()->getParameters();

        $instantiateParameters = [];
        foreach ($parameters as $parameterRef) {
            $name = $parameterRef->getName();
            foreach ($dependencies as $key => $dependencyValue) {
                if ($parameterRef->getType()->getName() === $key) {
                    $instantiateParameters[$name] = $dependencyValue;
                }
            }

            if (!$dependencyAttributes = $parameterRef->getAttributes(AutoDependencyAttribute::class)) {
                continue;
//                throw new InvalidArgumentException('Either value must be provided or DefaultValueProviderEnum specified');
            }

            $parameterDepends = null;
            foreach ($dependencyAttributes as $attribute) {
                $dependsOn = $attribute->getArguments()['dependsOn'];
                if ($dependsOn instanceof BackedEnum) {
                    $dependsOn = $dependsOn::class;
                }

                if ($parameterDepends !== null && $dependsOn !== $parameterDepends) {
                    throw new RuntimeException('All attributes must be dependent from same');
                } else {
                    $parameterDepends = $dependsOn;
                }
            }

            $specifiedDependencyValue = $dependencies[$parameterDepends] ?? null;

            foreach ($dependencyAttributes as $attribute) {
                $dependsOn = $attribute->getArguments()['dependsOn'];
                $dependentValue = $attribute->getArguments()['resultValue'];

                if ($dependsOn === $specifiedDependencyValue) {
                    $instantiateParameters[$name] = $dependentValue;
                    break;
                }
            }
        }

        return $ref->newInstance(...$instantiateParameters);
    }

    public function jsonSerialize(): array
    {
        return [
            'usedRiskLevel' => $this->usedRiskLevel,
            'minPercentOfDepositToUseAsMargin' => $this->minPercentOfDepositToUseAsMargin,
            'maxPercentOfDepositToUseAsMargin' => $this->maxPercentOfDepositToUseAsMargin
        ];
    }
}
