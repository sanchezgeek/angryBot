<?php

declare(strict_types=1);

namespace App\Tests\Mixin\DataProvider;

use App\Tests\Utils\TestData\TestCaseDataBase;
use Generator;

trait TestCaseAwareTest
{
    /**
     * @param iterable<TestCaseDataBase> $cases
     * @return Generator<string, array>
     */
    private function testCasesIterator(iterable $cases): Generator
    {
        foreach ($cases as $case) {
            yield $case->name() => [$case->data()];
        }
    }

//    /**
//     * @param iterable<TestCaseDataBase> $cases
//     * @return array<string, array>
//     */
//    private function testCasesIterator(iterable $cases): array
//    {
//        $res = [];
//        foreach ($cases as $case) {
//            if (is_array($case)) {
//                $res = array_merge($res, $this->testCasesIterator($case));
//            } else {
//                $res[$case->name()] = [$case->data()];
//            }
//        }
//        return $res;
//    }
}
