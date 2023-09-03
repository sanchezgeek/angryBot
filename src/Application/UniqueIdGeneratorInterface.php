<?php

namespace App\Application;

interface UniqueIdGeneratorInterface
{
    public function generateUniqueId(string $prefix): string;
}
